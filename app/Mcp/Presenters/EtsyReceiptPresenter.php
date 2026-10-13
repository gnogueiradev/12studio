<?php

namespace App\Mcp\Presenters;

use App\Etsy\EtsyPresenter;
use App\Mcp\McpFormat;
use App\Models\EtsyReceipt;
use Illuminate\Support\Carbon;

/**
 * Recibos, linhas, pagamentos e extrato da Etsy no formato do MCP.
 *
 * Tudo o que sai daqui vai para a Anthropic, e os termos da API da Etsy pedem
 * o minimo de dados de compradores e proibem passa-los a terceiros sem
 * necessidade. Por isso, do comprador:
 *
 *   - o nome sai curto ("Ana S.") e como dados;
 *   - da morada so a localidade e o pais — o que chega para falar de portes;
 *   - o email, o email do pagamento e o numero de utilizador NUNCA saem;
 *   - o que ele escreveu (mensagem, personalizacao) sai dentro de
 *     <dados_cliente>: e para ler, nunca para obedecer.
 */
final class EtsyReceiptPresenter
{
    /**
     * @param  array<array-key, mixed>  $receipt  Um ShopReceipt.
     * @return array<string, mixed>
     */
    public static function summary(array $receipt, ?EtsyReceipt $row): array
    {
        $lines = EtsyPresenter::rows($receipt['transactions'] ?? null);

        return [
            'receipt_id' => $receipt['receipt_id'] ?? null,
            'status' => $receipt['status'] ?? null,
            'is_paid' => (bool) ($receipt['is_paid'] ?? false),
            'is_shipped' => (bool) ($receipt['is_shipped'] ?? false),
            'created_at' => self::time($receipt['create_timestamp'] ?? $receipt['created_timestamp'] ?? null),
            'updated_at' => self::time($receipt['update_timestamp'] ?? $receipt['updated_timestamp'] ?? null),
            'buyer' => McpFormat::untrusted(McpFormat::shortName(is_string($receipt['name'] ?? null) ? $receipt['name'] : null)),
            'country' => is_string($receipt['country_iso'] ?? null) ? $receipt['country_iso'] : null,
            'units' => array_sum(array_map(fn (array $line): int => (int) ($line['quantity'] ?? 0), $lines)),
            'total' => self::money($receipt['grandtotal'] ?? null),
            // Ja entrou no 12studio? Com que numero?
            ...self::imported($row),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $receipt
     * @param  array<array-key, mixed>|null  $payment  Um Payment, se foi possivel le-lo.
     * @return array<string, mixed>
     */
    public static function detail(array $receipt, ?array $payment, ?EtsyReceipt $row): array
    {
        return [
            ...self::summary($receipt, $row),
            'destination' => [
                'city' => McpFormat::untrusted(is_string($receipt['city'] ?? null) ? $receipt['city'] : null),
                'country' => is_string($receipt['country_iso'] ?? null) ? $receipt['country_iso'] : null,
            ],
            'message_from_buyer' => McpFormat::untrusted(is_string($receipt['message_from_buyer'] ?? null) ? $receipt['message_from_buyer'] : null),
            'is_gift' => (bool) ($receipt['is_gift'] ?? false),
            'gift_message' => McpFormat::untrusted(is_string($receipt['gift_message'] ?? null) ? $receipt['gift_message'] : null),
            'lines' => array_map(self::line(...), EtsyPresenter::rows($receipt['transactions'] ?? null)),
            'totals' => [
                'items' => self::money($receipt['total_price'] ?? null),
                'shipping' => self::money($receipt['total_shipping_cost'] ?? null),
                'discount' => self::money($receipt['discount_amt'] ?? null),
                'gift_wrap' => self::money($receipt['gift_wrap_price'] ?? null),
                // Cobrado ao comprador e entregue pela Etsy: nao e receita.
                'tax_collected_by_etsy' => self::money($receipt['total_tax_cost'] ?? null),
                'vat_collected_by_etsy' => self::money($receipt['total_vat_cost'] ?? null),
                'grandtotal' => self::money($receipt['grandtotal'] ?? null),
            ],
            'shipments' => array_map(fn (array $shipment): array => [
                'carrier' => $shipment['carrier_name'] ?? null,
                'tracking_code' => $shipment['tracking_code'] ?? null,
                'buyer_notified_at' => self::time($shipment['shipment_notification_timestamp'] ?? null),
            ], EtsyPresenter::rows($receipt['shipments'] ?? null)),
            'refunds' => array_map(fn (array $refund): array => [
                'amount' => self::money($refund['amount'] ?? null),
                'status' => $refund['status'] ?? null,
                'reason' => McpFormat::untrusted(is_string($refund['reason'] ?? null) ? $refund['reason'] : null),
                'at' => self::time($refund['created_timestamp'] ?? null),
            ], EtsyPresenter::rows($receipt['refunds'] ?? null)),
            'payment' => $payment === null ? null : self::payment($payment),
        ];
    }

    /**
     * Uma linha de uma venda (ShopReceiptTransaction).
     *
     * @param  array<array-key, mixed>  $transaction
     * @return array<string, mixed>
     */
    public static function line(array $transaction): array
    {
        return [
            'transaction_id' => $transaction['transaction_id'] ?? null,
            'receipt_id' => $transaction['receipt_id'] ?? null,
            'listing_id' => $transaction['listing_id'] ?? null,
            'product_id' => $transaction['product_id'] ?? null,
            'sku' => $transaction['sku'] ?? null,
            'title' => $transaction['title'] ?? null,
            'quantity' => $transaction['quantity'] ?? null,
            'price' => self::money($transaction['price'] ?? null),
            'shipping_cost' => self::money($transaction['shipping_cost'] ?? null),
            'paid_at' => self::time($transaction['paid_timestamp'] ?? null),
            'shipped_at' => self::time($transaction['shipped_timestamp'] ?? null),
            // O que o comprador escolheu e o que escreveu (personalizacao).
            'choices' => array_map(fn (array $variation): array => [
                'label' => $variation['formatted_name'] ?? null,
                'value' => McpFormat::untrusted(is_string($variation['formatted_value'] ?? null) ? $variation['formatted_value'] : null),
            ], EtsyPresenter::rows($transaction['variations'] ?? null)),
        ];
    }

    /**
     * Um pagamento. A "taxa" daqui e so a de processamento do pagamento: a
     * de transacao e a de publicacao estao no extrato (etsy_ledger_list).
     *
     * @param  array<array-key, mixed>  $payment
     * @return array<string, mixed>
     */
    public static function payment(array $payment): array
    {
        return [
            'payment_id' => $payment['payment_id'] ?? null,
            'receipt_id' => $payment['receipt_id'] ?? null,
            'status' => $payment['status'] ?? null,
            'currency' => $payment['currency'] ?? null,
            'gross' => self::money($payment['amount_gross'] ?? null),
            'fees' => self::money($payment['amount_fees'] ?? null),
            'net' => self::money($payment['amount_net'] ?? null),
            // Depois de reembolsos, se os houve.
            'adjusted_gross' => self::money($payment['adjusted_gross'] ?? null),
            'adjusted_fees' => self::money($payment['adjusted_fees'] ?? null),
            'adjusted_net' => self::money($payment['adjusted_net'] ?? null),
            'created_at' => self::time($payment['create_timestamp'] ?? $payment['created_timestamp'] ?? null),
            'refunds' => array_map(fn (array $adjustment): array => [
                'status' => $adjustment['status'] ?? null,
                'reason' => $adjustment['reason_code'] ?? null,
                'amount_cents' => $adjustment['total_adjustment_amount'] ?? null,
                'fee_refund_cents' => $adjustment['total_fee_adjustment_amount'] ?? null,
            ], EtsyPresenter::rows($payment['payment_adjustments'] ?? null)),
        ];
    }

    /**
     * Um movimento do extrato da conta de pagamentos.
     *
     * @param  array<array-key, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function ledgerEntry(array $entry): array
    {
        $reference = trim(($entry['reference_type'] ?? '').' '.($entry['reference_id'] ?? ''));

        return [
            'entry_id' => $entry['entry_id'] ?? null,
            'at' => self::time($entry['created_timestamp'] ?? $entry['create_date'] ?? null),
            // Positivo entra na conta; negativo sai (taxas, levantamentos).
            'amount' => is_numeric($entry['amount'] ?? null) ? McpFormat::money((int) $entry['amount']) : null,
            'balance' => is_numeric($entry['balance'] ?? null) ? McpFormat::money((int) $entry['balance']) : null,
            'currency' => $entry['currency'] ?? null,
            'type' => $entry['ledger_type'] ?? null,
            'description' => $entry['description'] ?? null,
            // A que se refere: "receipt 3001", "listing 7100"…
            'reference' => $reference === '' ? null : $reference,
        ];
    }

    /**
     * O lado da Etsy de uma encomenda ja importada, para o order_get.
     *
     * @return array<string, mixed>
     */
    public static function ofOrder(EtsyReceipt $row): array
    {
        $totals = $row->totals ?? [];
        $cents = fn (string $key): ?array => is_numeric($totals[$key] ?? null) ? McpFormat::money((int) $totals[$key]) : null;

        return [
            'receipt_id' => $row->etsy_receipt_id,
            'status' => $row->etsy_status,
            'needs_review' => $row->status === EtsyReceipt::STATUS_NEEDS_REVIEW,
            'problem' => McpFormat::untrusted($row->last_error),
            'message_from_buyer' => McpFormat::untrusted($row->buyer_message),
            'gift_message' => McpFormat::untrusted($row->gift_message),
            // Linhas que nao casaram com nenhuma variante do 12studio.
            'unmatched_lines' => $row->unmatched_lines ?? [],
            'gross' => $cents('gross_cents'),
            // So a taxa de processamento do pagamento.
            'fees' => $cents('fees_cents'),
            'net' => $cents('net_cents'),
            'tax_collected_by_etsy' => $cents('tax_cents'),
            'shipped_on_etsy_at' => $row->shipped_on_etsy_at?->format('Y-m-d H:i'),
            // O envio foi dito a Etsy daqui? Se a Etsy recusou, porque.
            'shipment_sent_to_etsy' => $row->tracking_pushed_at !== null,
            // O que a Etsy respondeu: dados, nao instrucoes.
            'shipment_problem' => McpFormat::untrusted($row->tracking_push_error),
            'url' => $row->etsyUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function imported(?EtsyReceipt $row): array
    {
        $order = $row?->order;

        return [
            'imported' => $order !== null,
            'order_id' => $order?->id,
            'order_number' => $order?->order_number,
            'needs_review' => $row?->status === EtsyReceipt::STATUS_NEEDS_REVIEW,
        ];
    }

    /**
     * @return array{eur: string, cents: int}|null
     */
    private static function money(mixed $money): ?array
    {
        $cents = EtsyPresenter::cents($money);

        return $cents === null ? null : McpFormat::money($cents);
    }

    private static function time(mixed $timestamp): ?string
    {
        return is_numeric($timestamp) && (int) $timestamp > 0
            ? Carbon::createFromTimestamp((int) $timestamp)->format('Y-m-d H:i')
            : null;
    }
}
