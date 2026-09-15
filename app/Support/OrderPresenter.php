<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemStatusHistory;
use App\Models\OrderStatusHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Traduz uma encomenda para a forma que as paginas Inertia consomem
 * (camelCase, ja resolvida). Vive em Support e nao no controller porque o
 * detalhe e a timeline sao reutilizados pelo quadro de producao.
 */
class OrderPresenter
{
    private const PIPELINE = [
        'pending_payment', 'paid', 'in_production', 'ready_to_ship', 'shipped', 'delivered',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function detail(Order $order): array
    {
        $order->load(['items.statusHistories.changedBy', 'statusHistories.changedBy', 'createdBy', 'tags']);

        return [
            'id' => $order->id,
            'orderNumber' => $order->order_number,
            'customerName' => $order->customer_name,
            'email' => $order->email,
            'phone' => $order->phone,
            'nif' => $order->nif,
            'customerId' => $order->user_id,
            'status' => $order->status,
            'paymentStatus' => $order->payment_status,
            'paymentMethod' => $order->payment_method,
            'salesChannel' => $order->sales_channel,
            'externalOrderReference' => $order->external_order_reference,
            'createdBy' => $order->createdBy?->name,
            'subtotalCents' => $order->subtotal_cents,
            'shippingCents' => $order->shipping_cents,
            'adjustmentCents' => $order->adjustment_cents,
            'adjustmentReason' => $order->adjustment_reason,
            'totalCents' => $order->total_cents,
            'shippingAddress' => $order->shipping_address,
            'billingAddress' => $order->billing_address,
            'shippingMethodName' => $order->shipping_method_name,
            'trackingNumber' => $order->tracking_number,
            'trackingUrl' => $order->tracking_url,
            'adminNote' => $order->admin_note,
            'tags' => $order->tags->pluck('name')->all(),
            'stockIssue' => $order->stock_issue,
            'createdAt' => $order->created_at?->format('Y-m-d H:i'),
            'paidAt' => $order->paid_at?->format('Y-m-d H:i'),
            'shippedAt' => $order->shipped_at?->format('Y-m-d H:i'),
            'deliveredAt' => $order->delivered_at?->format('Y-m-d H:i'),
            'cancelledAt' => $order->cancelled_at?->format('Y-m-d H:i'),
            'customerOrdersCount' => $order->user_id === null
                ? null
                : Order::query()->where('user_id', $order->user_id)->count(),
            'availableStatuses' => self::availableStatuses($order),
            'progress' => self::progress($order),
            'items' => $order->items->map(self::item(...))->all(),
            'timeline' => self::timeline($order),
        ];
    }

    /**
     * A barra de progresso do topo do detalhe: um degrau por estado do
     * pipeline, com a hora a que a encomenda la chegou.
     *
     * So mostra o caminho que esta encomenda faz de facto. Um degrau que ficou
     * para tras sem nunca ter sido pisado (a producao de uma encomenda so de
     * stock, o envio de uma venda em mao) desaparece em vez de ficar cinzento
     * a fingir que falta. Pelo mesmo motivo, os degraus futuros que se sabe
     * que vao ser saltados tambem nao aparecem.
     *
     * @return array<int, array{status: string, state: string, at: string|null}>
     */
    public static function progress(Order $order): array
    {
        $reachedAt = ['pending_payment' => $order->created_at];

        foreach ($order->statusHistories->sortBy('id') as $history) {
            /** @var OrderStatusHistory $history */
            // `from === to` sao eventos de pagamento e ajuste, nao degraus.
            if ($history->from_status !== $history->to_status && ! isset($reachedAt[$history->to_status])) {
                $reachedAt[$history->to_status] = $history->created_at;
            }
        }

        $terminal = in_array($order->status, ['cancelled', 'refunded'], true);

        // Numa encomenda fechada, o "onde ia" e o degrau mais avancado a que
        // chegou antes de morrer.
        $currentIndex = $terminal
            ? max(array_keys(array_filter(
                self::PIPELINE,
                fn (string $status): bool => isset($reachedAt[$status]),
            )) ?: [0])
            : (int) array_search($order->status, self::PIPELINE, true);

        $needsProduction = $order->items->contains(
            fn (OrderItem $item): bool => $item->production_status !== 'not_required',
        );

        $steps = [];

        foreach (self::PIPELINE as $index => $status) {
            $at = $reachedAt[$status] ?? null;

            if ($index <= $currentIndex) {
                if ($at === null && $index > 0) {
                    continue;
                }

                $state = $index === $currentIndex && ! $terminal ? 'current' : 'done';
            } else {
                $skipped = ($status === 'in_production' && ! $needsProduction)
                    || ($status === 'shipped' && $order->shipping_address === null);

                if ($terminal || $skipped) {
                    continue;
                }

                $state = 'todo';
            }

            $steps[] = [
                'status' => $status,
                'state' => $state,
                'at' => self::stamp($at, $order),
            ];
        }

        if ($terminal) {
            $steps[] = [
                'status' => $order->status,
                'state' => 'failed',
                'at' => self::stamp($reachedAt[$order->status] ?? null, $order),
            ];
        }

        return $steps;
    }

    /**
     * Hora curta para a barra: so "HH:MM" no dia em que a encomenda foi
     * registada, e a data a frente nos outros dias.
     */
    private static function stamp(?CarbonInterface $at, Order $order): ?string
    {
        if ($at === null) {
            return null;
        }

        return $order->created_at !== null && $at->isSameDay($order->created_at)
            ? $at->format('H:i')
            : ShortDate::of($at).', '.$at->format('H:i');
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(OrderItem $item): array
    {
        return [
            'id' => $item->id,
            'productName' => $item->product_name,
            'variantLabel' => $item->variant_label,
            'sku' => $item->sku,
            'qty' => $item->qty,
            'unitPriceCents' => $item->unit_price_cents,
            'catalogUnitPriceCents' => $item->catalog_unit_price_cents,
            'priceOverrideReason' => $item->price_override_reason,
            'personalizationSurchargeCents' => $item->personalization_surcharge_cents,
            'lineTotalCents' => $item->line_total_cents,
            'vatRate' => $item->vat_rate,
            'fulfillmentMode' => $item->fulfillment_mode,
            'productionStatus' => $item->production_status,
            'personalization' => self::personalization($item),
        ];
    }

    /**
     * Personalizacao renderizada com etiquetas legiveis — o admin nunca ve
     * JSON bruto no detalhe nem no quadro de producao.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function personalization(OrderItem $item): array
    {
        $entries = [];

        foreach ($item->personalization ?? [] as $key => $value) {
            $entries[] = [
                'label' => Str::of((string) $key)->replace('_', ' ')->ucfirst()->value(),
                'value' => is_scalar($value)
                    ? (string) $value
                    : (json_encode($value, JSON_UNESCAPED_UNICODE) ?: ''),
            ];
        }

        return $entries;
    }

    /**
     * Estados de fulfilment ainda alcancaveis. Espelha as regras do
     * OrderService — o service continua a ser quem decide; isto so evita
     * mostrar botoes que iriam rebentar.
     *
     * @return array<int, string>
     */
    public static function availableStatuses(Order $order): array
    {
        if (in_array($order->status, ['cancelled', 'refunded'], true)) {
            return [];
        }

        $index = array_search($order->status, self::PIPELINE, true);
        $forward = $index === false ? [] : array_slice(self::PIPELINE, $index + 1);

        // `paid` nunca e um passo manual: chega-se la marcando o pagamento,
        // e o OrderService recusa-o com o pagamento pendente.
        $forward = array_values(array_diff($forward, ['paid']));

        return [...$forward, 'cancelled', 'refunded'];
    }

    /**
     * Timeline unica: os dois historicos (encomenda e producao por item)
     * intercalados por data, do mais recente para o mais antigo.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function timeline(Order $order): array
    {
        $entries = [];
        // Os timestamps tem precisao de segundo: varias transicoes seguidas
        // (pagar -> paid -> in_production -> item a imprimir) caem todas no
        // mesmo segundo. `seq` desempata pela ordem de insercao, para o
        // historico nunca aparecer baralhado entre recargas.
        $seq = 0;

        foreach ($order->statusHistories as $history) {
            /** @var OrderStatusHistory $history */
            $entries[] = [
                'id' => 'order-'.$history->id,
                'kind' => 'order',
                'subject' => null,
                'fromStatus' => $history->from_status,
                'toStatus' => $history->to_status,
                'note' => $history->note,
                'author' => $history->changedBy?->name,
                'at' => $history->created_at?->format('Y-m-d H:i') ?? '',
                'day' => ShortDate::of($history->created_at),
                'time' => $history->created_at?->format('H:i'),
                ...self::orderEvent($history),
                'sortKey' => $history->created_at?->getTimestamp() ?? 0,
                'seq' => $seq++,
            ];
        }

        foreach ($order->items as $item) {
            foreach ($item->statusHistories as $history) {
                /** @var OrderItemStatusHistory $history */
                $entries[] = [
                    'id' => 'item-'.$history->id,
                    'kind' => 'item',
                    'subject' => $item->product_name,
                    'fromStatus' => $history->from_status,
                    'toStatus' => $history->to_status,
                    'note' => $history->note,
                    'author' => $history->changedBy?->name,
                    'at' => $history->created_at?->format('Y-m-d H:i') ?? '',
                    'day' => ShortDate::of($history->created_at),
                    'time' => $history->created_at?->format('H:i'),
                    'category' => 'item',
                    'itemId' => $item->id,
                    'sortKey' => $history->created_at?->getTimestamp() ?? 0,
                    'seq' => $seq++,
                ];
            }
        }

        usort(
            $entries,
            fn (array $a, array $b): int => [$b['sortKey'], $b['seq']] <=> [$a['sortKey'], $a['seq']],
        );

        return array_map(function (array $entry): array {
            unset($entry['sortKey'], $entry['seq']);

            return $entry;
        }, $entries);
    }

    /**
     * Uma linha do historico da encomenda, ja separada no que ela e.
     *
     * O pagamento e o ajuste gravam-se como `from === to` com a mudanca
     * escrita na nota (ver OrderService::setPaymentStatus e setAdjustment).
     * Desmonta-se aqui, ao lado de quem conhece esse formato, para o ecra
     * nunca mostrar "pending -> paid" em bruto. Uma nota que nao bata com o
     * formato fica como esta — perde o resumo, nunca o conteudo.
     *
     * @return array<string, mixed>
     */
    private static function orderEvent(OrderStatusHistory $history): array
    {
        $note = (string) $history->note;

        if ($history->from_status === $history->to_status) {
            if (preg_match('/^Pagamento: (\w+) -> (\w+)\.\s*(.*)$/s', $note, $match) === 1) {
                return [
                    'category' => 'payment',
                    'paymentFrom' => $match[1],
                    'paymentTo' => $match[2],
                    'note' => $match[3] === '' ? null : $match[3],
                ];
            }

            if (preg_match('/^Total ajustado: (-?[\d.]+) -> (-?[\d.]+) \([+-]?-?[\d.]+\)\.\s*(.*)$/s', $note, $match) === 1) {
                return [
                    'category' => 'adjustment',
                    'fromCents' => Money::fromDecimal($match[1]),
                    'toCents' => Money::fromDecimal($match[2]),
                    'note' => $match[3] === '' ? null : $match[3],
                ];
            }
        }

        return ['category' => 'state'];
    }
}
