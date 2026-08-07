<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemStatusHistory;
use App\Models\OrderStatusHistory;
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
        $order->load(['items.statusHistories.changedBy', 'statusHistories.changedBy', 'createdBy']);

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
            'totalCents' => $order->total_cents,
            'shippingAddress' => $order->shipping_address,
            'billingAddress' => $order->billing_address,
            'shippingMethodName' => $order->shipping_method_name,
            'trackingNumber' => $order->tracking_number,
            'trackingUrl' => $order->tracking_url,
            'adminNote' => $order->admin_note,
            'stockIssue' => $order->stock_issue,
            'createdAt' => $order->created_at?->format('Y-m-d H:i'),
            'paidAt' => $order->paid_at?->format('Y-m-d H:i'),
            'shippedAt' => $order->shipped_at?->format('Y-m-d H:i'),
            'deliveredAt' => $order->delivered_at?->format('Y-m-d H:i'),
            'cancelledAt' => $order->cancelled_at?->format('Y-m-d H:i'),
            'availableStatuses' => self::availableStatuses($order),
            'items' => $order->items->map(self::item(...))->all(),
            'timeline' => self::timeline($order),
        ];
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
}
