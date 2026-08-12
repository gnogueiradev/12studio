<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Variant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pagina de entrada do backoffice. Substitui o Route::inertia() da Fase 1,
 * que renderizava o componente sem props nenhumas.
 *
 * Regra que atravessa o ficheiro todo: RECEITA sai de `payment_status`, nunca
 * de `status`. Sao dois eixos independentes (ver o comentario no proprio
 * Order) e uma encomenda pode estar `shipped` sem nunca ter sido paga —
 * misturar os dois daria numeros que ninguem consegue reconciliar com o banco.
 */
class DashboardController extends Controller
{
    /** Dias que uma encomenda pode ficar em producao antes de virar alerta. */
    private const STUCK_IN_PRODUCTION_DAYS = 3;

    /** Semanas na barra de vendas. */
    private const CHART_WEEKS = 12;

    /**
     * O KPI de stock baixo e a tira de alertas precisam da mesma lista. Sem
     * isto o pedido corria a query duas vezes.
     *
     * @var Collection<int, Variant>|null
     */
    private ?Collection $lowStock = null;

    public function __invoke(): Response
    {
        $now = CarbonImmutable::now();

        return Inertia::render('admin/dashboard', [
            'today' => $this->today($now),
            'storeOpen' => (bool) config('access.store_open'),
            'kpis' => $this->kpis($now),
            'statusCounts' => $this->statusCounts(),
            'weeklySales' => $this->weeklySales($now),
            'recentOrders' => $this->recentOrders(),
            'production' => $this->production(),
            'alerts' => $this->alerts($now),
        ]);
    }

    /**
     * "Terca, 12 de agosto". O locale vai fixo em vez de sair do
     * config('app.locale') porque o backoffice e todo em portugues — a data no
     * cabecalho nao deve mudar de idioma so porque alguem correu os testes com
     * APP_LOCALE=en.
     *
     * Via settings() e nao locale(): o locale() do Carbon e getter e setter ao
     * mesmo tempo, e o tipo de retorno (CarbonInterface|string) nao encadeia.
     */
    private function today(CarbonImmutable $now): string
    {
        return ucfirst($this->inPortuguese($now)->translatedFormat('l, j \d\e F'));
    }

    private function inPortuguese(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->settings(['locale' => 'pt_PT']);
    }

    /**
     * @return array<string, mixed>
     */
    private function kpis(CarbonImmutable $now): array
    {
        $since = $now->subDays(30);

        // Duas janelas de 30 dias lado a lado para a variacao percentual.
        $revenue = $this->paidTotalBetween($since, $now);
        $previous = $this->paidTotalBetween($now->subDays(60), $since);

        $paidOrders = Order::query()
            ->where('payment_status', 'paid')
            ->whereBetween('paid_at', [$since, $now])
            ->count();

        $lowStock = $this->lowStockVariants();

        return [
            'revenue30Cents' => $revenue,
            // null (e nao 0) quando nao ha periodo anterior: "+100%" a partir
            // do nada nao quer dizer nada, e a interface esconde a linha.
            'revenueDeltaPercent' => $previous > 0
                ? (int) round(($revenue - $previous) / $previous * 100)
                : null,
            'ordersThisWeek' => Order::query()
                ->where('created_at', '>=', $now->startOfWeek())
                ->whereNot('status', 'cancelled')
                ->count(),
            'awaitingPayment' => Order::query()->where('status', 'pending_payment')->count(),
            'paidOrders30' => $paidOrders,
            'avgOrderCents' => $paidOrders > 0 ? (int) round($revenue / $paidOrders) : 0,
            'lowStockCount' => $lowStock->count(),
            'lowStockNames' => $lowStock
                ->take(2)
                ->map(fn (Variant $variant): string => $variant->product->name)
                ->values()
                ->all(),
        ];
    }

    private function paidTotalBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) Order::query()
            ->where('payment_status', 'paid')
            ->whereBetween('paid_at', [$from, $to])
            ->sum('total_cents');
    }

    /**
     * `available_stock` e um acessor, nao uma coluna — o filtro tem de refazer
     * a subtracao em SQL. So conta o que esta mesmo a venda: uma variante
     * inativa ou de um produto em rascunho a ficar sem stock nao e accionavel.
     *
     * @return Collection<int, Variant>
     */
    private function lowStockVariants(): Collection
    {
        return $this->lowStock ??= Variant::query()
            ->where('active', true)
            ->whereHas('product', fn ($query) => $query->where('status', 'active'))
            ->whereRaw('stock - reserved_stock <= low_stock_threshold')
            ->with('product')
            ->orderByRaw('stock - reserved_stock')
            ->get();
    }

    /**
     * Distribuicao pelo pipeline aberto. `delivered`, `cancelled` e `refunded`
     * ficam de fora: sao estados terminais e enchiam o grafico com historico
     * em vez de trabalho por fazer.
     *
     * @return list<array<string, mixed>>
     */
    private function statusCounts(): array
    {
        $open = ['pending_payment', 'paid', 'in_production', 'ready_to_ship', 'shipped'];

        $counts = Order::query()
            ->whereIn('status', $open)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Percorre a lista e nao o resultado: um estado sem encomendas tem de
        // aparecer a zero, senao as barras saltam de sitio entre visitas.
        return array_map(fn (string $status): array => [
            'status' => $status,
            'count' => (int) ($counts[$status] ?? 0),
        ], $open);
    }

    /**
     * Doze semanas de receita.
     *
     * Os baldes fazem-se em PHP e nao em SQL de proposito: producao corre
     * SQLite e o agrupamento por semana nao tem sintaxe comum entre SQLite
     * (strftime) e MySQL (WEEK). Com o volume desta loja sao poucas dezenas de
     * linhas — nao vale a pena um raw query por motor de base de dados.
     *
     * @return list<array<string, mixed>>
     */
    private function weeklySales(CarbonImmutable $now): array
    {
        $firstWeek = $now->startOfWeek()->subWeeks(self::CHART_WEEKS - 1);

        $orders = Order::query()
            ->where('payment_status', 'paid')
            ->where('paid_at', '>=', $firstWeek)
            ->get(['paid_at', 'total_cents']);

        return array_map(function (int $offset) use ($firstWeek, $orders, $now): array {
            $start = $firstWeek->addWeeks($offset);
            $end = $start->addWeek();

            return [
                'weekLabel' => $this->inPortuguese($start)->translatedFormat('j M'),
                'month' => $this->inPortuguese($start)->translatedFormat('M'),
                'cents' => (int) $orders
                    ->filter(fn (Order $order): bool => $order->paid_at >= $start && $order->paid_at < $end)
                    ->sum('total_cents'),
                // O mes corrente sai destacado a dourado, como no design.
                'current' => $start->isSameMonth($now),
            ];
        }, range(0, self::CHART_WEEKS - 1));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentOrders(): array
    {
        // array_values e nao ->values(): so a funcao nativa e que devolve uma
        // lista aos olhos do analisador — o ->all() da Collection continua a
        // ser array<int, …> com buracos possiveis.
        return array_values(Order::query()
            ->with(['items:id,order_id,product_name,qty'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'orderNumber' => $order->order_number,
                'customerName' => $order->customer_name,
                'status' => $order->status,
                'totalCents' => $order->total_cents,
                'summary' => $this->itemsSummary($order),
            ])
            ->all());
    }

    /**
     * "Vaso ondulado · 2 un." — e "+2" quando a encomenda tem mais linhas. A
     * tabela do painel tem meia largura; o nome do primeiro item chega para
     * reconhecer a encomenda, o detalhe esta a um clique.
     */
    private function itemsSummary(Order $order): string
    {
        $first = $order->items->first();

        if ($first === null) {
            return '—';
        }

        $summary = $first->product_name.' · '.$first->qty.' un.';
        $rest = $order->items->count() - 1;

        return $rest > 0 ? $summary.' +'.$rest : $summary;
    }

    /**
     * O que esta na impressora agora. Substitui os cartoes "Impressora" e
     * "Filamento" do design: nao ha modelo de trabalho de impressao nem de
     * stock de filamento, e o backoffice nao mostra numeros inventados.
     *
     * @return array<string, mixed>
     */
    private function production(): array
    {
        $dead = ['cancelled', 'refunded'];

        return [
            'printing' => OrderItem::query()
                ->where('production_status', 'printing')
                ->whereHas('order', fn ($query) => $query->whereNotIn('status', $dead))
                ->with('order:id,order_number,customer_name')
                ->orderBy('created_at')
                ->limit(5)
                ->get()
                ->map(fn (OrderItem $item): array => [
                    'id' => $item->id,
                    'orderId' => $item->order->id,
                    'orderNumber' => $item->order->order_number,
                    'customerName' => $item->order->customer_name,
                    'productName' => $item->product_name,
                    'variantLabel' => $item->variant_label,
                    'qty' => $item->qty,
                ])
                ->all(),
            'queued' => OrderItem::query()
                ->where('production_status', 'awaiting_production')
                ->whereHas('order', fn ($query) => $query->whereNotIn('status', $dead))
                ->count(),
        ];
    }

    /**
     * A tira de alertas so mostra sinais reais e desaparece quando nao ha
     * nenhum — um painel que grita todos os dias deixa de ser lido.
     *
     * @return list<array<string, string>>
     */
    private function alerts(CarbonImmutable $now): array
    {
        $alerts = [];

        $stuck = Order::query()
            ->where('status', 'in_production')
            ->where('created_at', '<=', $now->subDays(self::STUCK_IN_PRODUCTION_DAYS))
            ->orderBy('created_at')
            ->get(['id', 'order_number', 'created_at']);

        foreach ($stuck as $order) {
            $days = (int) ($order->created_at?->diffInDays($now) ?? self::STUCK_IN_PRODUCTION_DAYS);

            $alerts[] = [
                'label' => "#{$order->order_number} está há {$days} dias em produção",
                'href' => route('admin.encomendas.show', $order->id, false),
            ];
        }

        $stockIssues = Order::query()->where('stock_issue', true)->count();

        if ($stockIssues > 0) {
            $alerts[] = [
                'label' => $stockIssues === 1
                    ? '1 encomenda com problema de stock'
                    : "{$stockIssues} encomendas com problema de stock",
                'href' => route('admin.encomendas.index', [], false),
            ];
        }

        $lowStock = $this->lowStockVariants()->count();

        if ($lowStock > 0) {
            $alerts[] = [
                'label' => $lowStock === 1
                    ? '1 variante abaixo do limite de stock'
                    : "{$lowStock} variantes abaixo do limite de stock",
                'href' => route('admin.produtos.index', [], false),
            ];
        }

        return $alerts;
    }
}
