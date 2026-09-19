<?php

namespace App\Mcp\Presenters;

use App\Mcp\McpFormat;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Support\OrderPresenter as BackofficeOrderPresenter;

/**
 * Encomendas no formato do MCP — com menos do que o backoffice mostra.
 *
 * Tudo o que sai daqui vai para a Anthropic. Por isso:
 *   - NIF e morada completa nunca saem; da morada so a localidade;
 *   - email e telefone saem mascarados;
 *   - o nome sai curto na listagem ("Ana S.") e completo so no detalhe;
 *   - tudo o que um cliente escreveu (nome, personalizacoes, localidade)
 *     vai dentro de <dados_cliente>, e a nota interna tambem (pode ter
 *     sido copiada de uma mensagem do cliente).
 */
final class OrderPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'created_at' => $order->created_at?->format('Y-m-d H:i'),
            'customer' => McpFormat::untrusted(McpFormat::shortName($order->customer_name)),
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'sales_channel' => $order->sales_channel,
            'total' => McpFormat::money($order->total_cents),
            'items' => (int) ($order->getAttribute('items_count') ?? $order->items->count()),
            'stock_issue' => $order->stock_issue,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Order $order): array
    {
        $city = is_array($order->shipping_address) ? ($order->shipping_address['city'] ?? null) : null;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'created_at' => $order->created_at?->format('Y-m-d H:i'),
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'sales_channel' => $order->sales_channel,
            'external_reference' => McpFormat::untrusted($order->external_order_reference),
            'next_statuses' => BackofficeOrderPresenter::availableStatuses($order),
            'customer' => [
                'name' => McpFormat::untrusted($order->customer_name),
                'email' => McpFormat::mask($order->email),
                'phone' => McpFormat::mask($order->phone),
                'city' => McpFormat::untrusted(is_string($city) ? $city : null),
                'registered' => $order->user_id !== null,
            ],
            'items' => $order->items->map(fn (OrderItem $item): array => self::item($item))->values()->all(),
            'totals' => [
                'subtotal' => McpFormat::money($order->subtotal_cents),
                'shipping' => McpFormat::money($order->shipping_cents),
                'adjustment' => McpFormat::money($order->adjustment_cents),
                'adjustment_reason' => $order->adjustment_reason,
                'total' => McpFormat::money($order->total_cents),
            ],
            'shipping' => [
                'method' => $order->shipping_method_name,
                'tracking_number' => $order->tracking_number,
            ],
            'dates' => [
                'paid_at' => $order->paid_at?->format('Y-m-d H:i'),
                'shipped_at' => $order->shipped_at?->format('Y-m-d H:i'),
                'delivered_at' => $order->delivered_at?->format('Y-m-d H:i'),
                'cancelled_at' => $order->cancelled_at?->format('Y-m-d H:i'),
            ],
            'history' => $order->statusHistories
                ->sortBy('id')
                ->map(fn (OrderStatusHistory $history): array => [
                    'at' => $history->created_at?->format('Y-m-d H:i'),
                    'from' => $history->from_status,
                    'to' => $history->to_status,
                    'note' => McpFormat::untrusted($history->note),
                ])
                ->values()
                ->all(),
            'internal_note' => McpFormat::untrusted($order->admin_note),
            'stock_issue' => $order->stock_issue,
            'tags' => $order->tags->pluck('name')->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(OrderItem $item): array
    {
        return [
            'id' => $item->id,
            'product' => $item->product_name,
            'variant' => $item->variant_label,
            'variant_id' => $item->variant_id,
            'sku' => $item->sku,
            'qty' => $item->qty,
            'unit_price' => McpFormat::money($item->unit_price_cents),
            'line_total' => McpFormat::money($item->line_total_cents),
            'production_status' => $item->production_status,
            'personalization' => collect(BackofficeOrderPresenter::personalization($item))
                ->map(fn (array $entry): array => [
                    'label' => $entry['label'],
                    'value' => McpFormat::untrusted($entry['value']),
                ])
                ->values()
                ->all(),
        ];
    }
}
