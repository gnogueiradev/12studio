<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Support\OrderPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quadro de producao ao nivel do ITEM — nao da encomenda. Uma encomenda
 * mista (uma peca em stock + uma por imprimir) so mostra aqui a segunda,
 * e so avanca para expedicao quando essa ficar pronta.
 */
class ProductionBoardController extends Controller
{
    /**
     * Quanto tempo um item pronto fica na coluna "Pronto". Depois disso ja
     * nao e trabalho da oficina: a encomenda continua na lista de encomendas
     * ("Pronto a enviar") ate sair.
     */
    private const READY_VISIBLE_HOURS = 24;

    /** Encomendas que ja sairam: os itens prontos delas saem logo do quadro. */
    private const GONE_ORDER_STATUSES = ['shipped', 'delivered'];

    public function __invoke(): Response
    {
        $items = OrderItem::query()
            ->whereIn('production_status', ['awaiting_production', 'printing', 'quality_check', 'ready'])
            // Encomendas mortas nao ocupam o quadro.
            ->whereHas('order', fn ($query) => $query->whereNotIn('status', ['cancelled', 'refunded']))
            ->where(fn (Builder $query) => $query
                ->where('production_status', '!=', 'ready')
                ->orWhere(fn (Builder $ready) => $this->stillFreshlyReady($ready)))
            ->with([
                'order',
                // A variante so entra pelo tempo de impressao estimado.
                'variant',
                // Sem limit(): um cartao entra em `printing` uma mao-cheia de
                // vezes, e o limite por pai em eager loads e fragil.
                'statusHistories' => fn ($query) => $query->where('to_status', 'printing')->latest('id'),
            ])
            ->orderBy('created_at')
            ->get();

        // O contexto "1 de 2" e o estado dos irmaos saem do grupo, nao da
        // linha: um cartao sozinho nao sabe se a encomenda ja pode seguir.
        //
        // Os irmaos vem de uma consulta propria e nao do que ficou visivel: um
        // item pronto ha dois dias ja saiu do quadro, mas continua a ser o
        // "1 de 2" da peca que ainda esta a imprimir.
        $byOrder = OrderItem::query()
            ->whereIn('order_id', $items->pluck('order_id')->unique())
            ->where('production_status', '!=', 'not_required')
            ->get()
            ->groupBy('order_id');

        return Inertia::render('admin/producao/index', [
            'items' => $items
                ->map(fn (OrderItem $item): array => $this->card($item, $byOrder->get($item->order_id)))
                ->all(),
            'readyVisibleHours' => self::READY_VISIBLE_HOURS,
        ]);
    }

    /**
     * Pronto ha menos de READY_VISIBLE_HOURS, e com a encomenda ainda ca
     * dentro. "Pronto desde" e a ultima entrada em `ready` no historico do
     * item; sem historico (itens anteriores a ele), a ultima alteracao.
     *
     * @param  Builder<OrderItem>  $query
     */
    private function stillFreshlyReady(Builder $query): void
    {
        $since = now()->subHours(self::READY_VISIBLE_HOURS);

        $query->where('production_status', 'ready')
            ->whereHas('order', fn ($order) => $order->whereNotIn('status', self::GONE_ORDER_STATUSES))
            ->where(fn (Builder $fresh) => $fresh
                ->whereHas('statusHistories', fn ($history) => $history
                    ->where('to_status', 'ready')
                    ->where('created_at', '>=', $since))
                ->orWhere(fn (Builder $legacy) => $legacy
                    ->whereDoesntHave('statusHistories', fn ($history) => $history->where('to_status', 'ready'))
                    ->where('updated_at', '>=', $since)));
    }

    /**
     * @param  Collection<int, OrderItem>|null  $siblings  Itens em producao da mesma encomenda, este incluido.
     * @return array<string, mixed>
     */
    private function card(OrderItem $item, ?Collection $siblings): array
    {
        $ordered = ($siblings ?? new Collection([$item]))->sortBy('id')->values();
        $position = $ordered->search(fn (OrderItem $sibling): bool => $sibling->is($item));

        $minutes = $item->variant?->printing_time_minutes;

        return [
            'id' => $item->id,
            'orderId' => $item->order->id,
            'orderNumber' => $item->order->order_number,
            'customerName' => $item->order->customer_name,
            'productName' => $item->product_name,
            'variantLabel' => $item->variant_label,
            'qty' => $item->qty,
            'productionStatus' => $item->production_status,
            'personalization' => OrderPresenter::personalization($item),
            'orderedAt' => $item->order->created_at?->format('Y-m-d'),
            // Estimativa, nao medicao: a variante pode nem ter o tempo preenchido.
            'estimatedMinutes' => $minutes === null ? null : $minutes * $item->qty,
            'startedPrintingAt' => $item->statusHistories->first()?->created_at?->format('H:i'),
            'positionInOrder' => $position === false ? 1 : $position + 1,
            'totalInOrder' => $ordered->count(),
            'orderReadyToShip' => $ordered->every(
                fn (OrderItem $sibling): bool => $sibling->production_status === 'ready',
            ),
        ];
    }
}
