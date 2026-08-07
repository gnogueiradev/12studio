<?php

namespace App\Services;

use App\Mail\OrderShippedMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemStatusHistory;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Models\Variant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Unica via de escrita de estados de encomenda e de producao.
 *
 * Nada aqui e validado apenas na interface: as invariantes entre
 * `payment_status` (o indicador FINANCEIRO) e `status` (o pipeline de
 * fulfilment) vivem neste ficheiro e mais lado nenhum.
 */
class OrderService
{
    /**
     * Ordem do pipeline. As transicoes sao forward-only, mas com saltos
     * (uma encomenda so de artigos em stock salta producao).
     */
    private const PIPELINE = [
        'pending_payment', 'paid', 'in_production', 'ready_to_ship', 'shipped', 'delivered',
    ];

    /** Alcancaveis de qualquer estado nao-terminal; nao tem saida. */
    private const TERMINAL = ['cancelled', 'refunded'];

    /** Percurso de producao de um item que exige impressao. */
    private const PRODUCTION_PIPELINE = [
        'awaiting_production', 'printing', 'quality_check', 'ready',
    ];

    public function __construct(
        private StockService $stockService,
    ) {}

    /**
     * Numero humano do tipo "2026-0042".
     *
     * `UPDATE ... RETURNING` e um statement unico e atomico — em SQLite o
     * lockForUpdate() e no-op, e derivar o numero de uma contagem de linhas
     * daria buracos e colisoes.
     */
    public function nextOrderNumber(): string
    {
        $year = (int) now()->year;

        DB::table('order_sequences')->insertOrIgnore([
            'year' => $year,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = DB::select(
            'UPDATE order_sequences SET last_number = last_number + 1 WHERE year = ? RETURNING last_number',
            [$year],
        );

        if ($rows === []) {
            throw new RuntimeException("Nao foi possivel obter o proximo numero de encomenda para {$year}.");
        }

        return sprintf('%d-%04d', $year, (int) $rows[0]->last_number);
    }

    /**
     * Encomenda registada a mao (Vinted, Instagram, presencial). E isto que
     * torna o backoffice o centro de todos os canais: partilha stock,
     * numeracao e quadro de producao com a loja online.
     *
     * @param  array<string, mixed>  $data
     */
    public function createManual(array $data, User $admin): Order
    {
        return DB::transaction(function () use ($data, $admin): Order {
            $lines = $this->resolveLines($data['items']);

            $subtotal = array_sum(array_column($lines, 'line_total_cents'));
            $shipping = Money::fromDecimal((string) ($data['shipping_price'] ?? '0'));

            $order = Order::query()->create([
                'order_number' => $this->nextOrderNumber(),
                'user_id' => $data['user_id'] ?? null,
                'customer_name' => $data['customer_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'nif' => $data['nif'] ?? null,
                'status' => 'pending_payment',
                'payment_method' => $data['payment_method'] ?? null,
                'payment_status' => 'pending',
                'sales_channel' => $data['sales_channel'],
                'external_order_reference' => $data['external_order_reference'] ?? null,
                'created_by_user_id' => $admin->getKey(),
                'subtotal_cents' => $subtotal,
                'shipping_cents' => $shipping,
                'total_cents' => $subtotal + $shipping,
                'currency' => 'EUR',
                'shipping_address' => $this->addressSnapshot($data),
                'shipping_method_name' => $data['shipping_method_name'] ?? null,
                'admin_note' => $data['admin_note'] ?? null,
                'guest_access_token' => Str::random(64),
            ]);

            foreach ($lines as $line) {
                $item = $order->items()->create($line['attributes']);

                // So artigos em stock consomem stock. Made-to-order e custom
                // sao produzidos de novo — o que os limita e a capacidade,
                // nao a prateleira.
                if ($line['variant'] instanceof Variant && $line['attributes']['fulfillment_mode'] === 'in_stock') {
                    $ok = $this->stockService->decrement(
                        $line['variant'],
                        $item->qty,
                        'manual_order',
                        $order,
                        $admin,
                    );

                    if (! $ok) {
                        throw new RuntimeException(
                            "Sem stock suficiente para {$line['variant']->sku}."
                        );
                    }
                }
            }

            $this->recordOrderHistory($order, null, 'pending_payment', $admin, 'Encomenda registada no backoffice.');

            // O admin pode registar uma venda ja paga (Vinted garante o
            // pagamento); a transicao passa pelas mesmas invariantes.
            if (($data['payment_status'] ?? 'pending') !== 'pending') {
                $this->setPaymentStatus($order, (string) $data['payment_status'], $admin);
            }

            return $order->refresh();
        });
    }

    /**
     * Transicao de fulfilment. Forward-only, com saltos.
     *
     * `$force` e a unica porta para avancar com pagamento por regularizar —
     * exige nota e fica no historico com o autor.
     */
    public function transitionOrder(
        Order $order,
        string $to,
        ?User $by = null,
        ?string $note = null,
        bool $force = false,
    ): Order {
        if (! in_array($to, Order::STATUSES, true)) {
            throw new RuntimeException("Estado de encomenda desconhecido: {$to}.");
        }

        $from = $order->status;

        if ($from === $to) {
            return $order;
        }

        if (in_array($from, self::TERMINAL, true)) {
            throw new RuntimeException('Uma encomenda cancelada ou reembolsada nao volta a avancar.');
        }

        if (! in_array($to, self::TERMINAL, true)) {
            $fromIndex = array_search($from, self::PIPELINE, true);
            $toIndex = array_search($to, self::PIPELINE, true);

            if ($fromIndex === false || $toIndex === false || $toIndex <= $fromIndex) {
                throw new RuntimeException("Transicao invalida: {$from} -> {$to}.");
            }

            if (! $order->isPaid() && ! $force) {
                throw new RuntimeException(
                    'Uma encomenda so avanca depois de paga — usa o avanco manual com nota para forcar.'
                );
            }

            if (! $order->isPaid() && ($note === null || trim($note) === '')) {
                throw new RuntimeException('O avanco manual com pagamento pendente exige uma nota.');
            }
        }

        $order = DB::transaction(function () use ($order, $from, $to, $by, $note): Order {
            $attributes = ['status' => $to];

            // Cada estado carimba a sua data uma unica vez.
            $stamp = match ($to) {
                'paid' => 'paid_at',
                'shipped' => 'shipped_at',
                'delivered' => 'delivered_at',
                'cancelled' => 'cancelled_at',
                default => null,
            };

            if ($stamp !== null && $order->{$stamp} === null) {
                $attributes[$stamp] = now();
            }

            $order->update($attributes);

            if ($to === 'cancelled') {
                $this->restoreStock($order, $by);
            }

            $this->recordOrderHistory($order, $from, $to, $by, $note);

            return $order->refresh();
        });

        // Fora da transacao: nada de I/O externo dentro de uma escrita ao
        // SQLite (o Mailable e queued e ja usa afterCommit).
        if ($to === 'shipped') {
            Mail::to($order->email)->send(new OrderShippedMail($order));
        }

        return $order;
    }

    /**
     * O indicador financeiro. Marcar como pago e o que desbloqueia o
     * pipeline — e o que decide se a encomenda vai para producao ou direta
     * para expedicao.
     */
    public function setPaymentStatus(
        Order $order,
        string $paymentStatus,
        ?User $by = null,
        ?string $note = null,
    ): Order {
        if (! in_array($paymentStatus, Order::PAYMENT_STATUSES, true)) {
            throw new RuntimeException("Estado de pagamento desconhecido: {$paymentStatus}.");
        }

        if ($order->payment_status === $paymentStatus) {
            return $order;
        }

        $previous = $order->payment_status;

        DB::transaction(function () use ($order, $paymentStatus, $previous, $by, $note): void {
            $attributes = ['payment_status' => $paymentStatus];

            if ($paymentStatus === 'paid' && $order->paid_at === null) {
                $attributes['paid_at'] = now();
            }

            $order->update($attributes);

            $this->recordOrderHistory(
                $order,
                $order->status,
                $order->status,
                $by,
                trim(sprintf('Pagamento: %s -> %s. %s', $previous, $paymentStatus, $note ?? '')),
            );
        });

        $order->refresh();

        return match ($paymentStatus) {
            'paid' => $this->advanceAfterPayment($order, $by),
            'failed' => $this->transitionOrder($order, 'cancelled', $by, 'Pagamento falhou.'),
            'refunded' => $this->transitionOrder($order, 'refunded', $by, 'Pagamento reembolsado.'),
            default => $order,
        };
    }

    /**
     * Producao ao nivel do item. Quando o ultimo item fica pronto, a
     * encomenda avanca sozinha para ready_to_ship.
     */
    public function setItemProductionStatus(
        OrderItem $item,
        string $to,
        ?User $by = null,
        ?string $note = null,
    ): OrderItem {
        if (! in_array($to, OrderItem::PRODUCTION_STATUSES, true)) {
            throw new RuntimeException("Estado de producao desconhecido: {$to}.");
        }

        $from = $item->production_status;

        if ($from === 'not_required' || $to === 'not_required') {
            throw new RuntimeException('Itens em stock nao passam pelo quadro de producao.');
        }

        $fromIndex = array_search($from, self::PRODUCTION_PIPELINE, true);
        $toIndex = array_search($to, self::PRODUCTION_PIPELINE, true);

        if ($fromIndex === false || $toIndex === false || $toIndex <= $fromIndex) {
            throw new RuntimeException("Transicao de producao invalida: {$from} -> {$to}.");
        }

        DB::transaction(function () use ($item, $from, $to, $by, $note): void {
            $item->update(['production_status' => $to]);

            OrderItemStatusHistory::query()->create([
                'order_item_id' => $item->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'changed_by_user_id' => $by?->getKey(),
                'note' => $note,
            ]);
        });

        $this->autoAdvanceWhenAllReady($item->order->refresh(), $by);

        return $item->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDetails(Order $order, array $data): Order
    {
        $order->update([
            'admin_note' => $data['admin_note'] ?? null,
            'tracking_number' => $data['tracking_number'] ?? null,
            'tracking_url' => $data['tracking_url'] ?? null,
            'shipping_method_name' => $data['shipping_method_name'] ?? null,
        ]);

        return $order;
    }

    /**
     * Pago: itens em stock nao param em producao; qualquer item por produzir
     * leva a encomenda inteira para `in_production`.
     */
    private function advanceAfterPayment(Order $order, ?User $by): Order
    {
        if ($order->status !== 'pending_payment') {
            return $order;
        }

        $order = $this->transitionOrder($order, 'paid', $by, 'Pagamento confirmado.');

        $needsProduction = $order->items()
            ->where('production_status', '!=', 'not_required')
            ->exists();

        return $this->transitionOrder(
            $order,
            $needsProduction ? 'in_production' : 'ready_to_ship',
            $by,
            $needsProduction ? 'Itens enviados para producao.' : 'Sem producao necessaria.',
        );
    }

    private function autoAdvanceWhenAllReady(Order $order, ?User $by): void
    {
        if ($order->status !== 'in_production') {
            return;
        }

        $pending = $order->items()
            ->whereNotIn('production_status', ['not_required', 'ready'])
            ->exists();

        if (! $pending) {
            $this->transitionOrder($order, 'ready_to_ship', $by, 'Todos os itens prontos.');
        }
    }

    /**
     * Cancelar devolve a prateleira o que dela saiu — e so isso: itens
     * made_to_order/custom nunca chegaram a descontar stock.
     */
    private function restoreStock(Order $order, ?User $by): void
    {
        $items = $order->items()
            ->whereNotNull('variant_id')
            ->where('fulfillment_mode', 'in_stock')
            ->with('variant')
            ->get();

        foreach ($items as $item) {
            if ($item->variant instanceof Variant) {
                $this->stockService->increment(
                    $item->variant,
                    $item->qty,
                    'restock_cancel',
                    $order,
                    $by,
                    'Encomenda cancelada.',
                );
            }
        }
    }

    private function recordOrderHistory(
        Order $order,
        ?string $from,
        string $to,
        ?User $by,
        ?string $note,
    ): void {
        OrderStatusHistory::query()->create([
            'order_id' => $order->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'changed_by_user_id' => $by?->getKey(),
            'note' => $note === null || trim($note) === '' ? null : Str::limit(trim($note), 300, ''),
        ]);
    }

    /**
     * Resolve cada linha do formulario: variante do catalogo (preco vindo da
     * BD, nunca do cliente) ou linha livre para vendas fora do catalogo.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{variant: Variant|null, attributes: array<string, mixed>, line_total_cents: int}>
     */
    private function resolveLines(array $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $variant = null;
            $catalogPrice = null;
            $qty = (int) $item['qty'];
            $unitPrice = Money::fromDecimal((string) $item['unit_price']);
            $lineTotal = $unitPrice * $qty;

            if (empty($item['variant_id'])) {
                // Linha livre: tudo o que aparece na encomenda vem do que o
                // admin escreveu.
                $fulfillmentMode = (string) ($item['fulfillment_mode'] ?? 'in_stock');
                $vatRate = (int) ($item['vat_rate'] ?? config('shop.default_vat_rate', 23));
                $productName = (string) $item['product_name'];
                $variantLabel = $item['variant_label'] ?: null;
                $sku = $item['sku'] ?: null;
            } else {
                // Do catalogo: os dados vem SEMPRE da BD. Um `size_label`
                // vazio fica vazio — nunca cai para o texto livre da linha.
                $variant = Variant::query()->with('product')->findOrFail((int) $item['variant_id']);
                $catalogPrice = $variant->price_cents;
                $fulfillmentMode = $variant->product->fulfillment_mode;
                $vatRate = $variant->product->vat_rate;
                $productName = $variant->product->name;
                $variantLabel = $variant->size_label;
                $sku = $variant->sku;
            }

            $lines[] = [
                'variant' => $variant,
                'line_total_cents' => $lineTotal,
                'attributes' => [
                    'variant_id' => $variant?->getKey(),
                    'product_name' => $productName,
                    'variant_label' => $variantLabel,
                    'sku' => $sku,
                    'unit_price_cents' => $unitPrice,
                    'catalog_unit_price_cents' => $catalogPrice,
                    'price_override_reason' => ($catalogPrice !== null && $catalogPrice !== $unitPrice)
                        ? $item['price_override_reason']
                        : null,
                    'personalization_surcharge_cents' => 0,
                    'qty' => $qty,
                    'line_total_cents' => $lineTotal,
                    'vat_rate' => $vatRate,
                    'fulfillment_mode' => $fulfillmentMode,
                    'production_status' => $fulfillmentMode === 'in_stock'
                        ? 'not_required'
                        : 'awaiting_production',
                ],
            ];
        }

        return $lines;
    }

    /**
     * Snapshot imutavel: editar a morada do cliente mais tarde nunca pode
     * reescrever para onde esta encomenda foi enviada.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function addressSnapshot(array $data): ?array
    {
        if (empty($data['line1'])) {
            return null;
        }

        return [
            'name' => $data['customer_name'],
            'line1' => $data['line1'],
            'line2' => $data['line2'] ?: null,
            'postalCode' => $data['postal_code'],
            'city' => $data['city'],
            'country' => $data['country'] ?? 'PT',
            'phone' => $data['phone'] ?: null,
        ];
    }
}
