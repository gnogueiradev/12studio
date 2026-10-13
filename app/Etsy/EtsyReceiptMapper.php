<?php

namespace App\Etsy;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Um recibo da Etsy, lido para o que uma encomenda do 12studio precisa.
 *
 * Nao fala com ninguem nem grava nada: recebe o que a Etsy mandou e devolve
 * so o que interessa, ja em centimos e com nomes nossos. E aqui que se decide
 * o que do comprador NAO passa: o email, o email do pagamento e o numero de
 * utilizador ficam por ler.
 *
 * @phpstan-type MappedLine array{transaction_id: int|null, listing_id: int|null, product_id: int|null, sku: string|null, title: string, quantity: int, unit_price_cents: int, choices: array<string, string>}
 * @phpstan-type MappedReceipt array{
 *     receipt_id: int,
 *     etsy_status: string,
 *     is_paid: bool,
 *     is_shipped: bool,
 *     is_canceled: bool,
 *     refund: 'full'|'partial'|null,
 *     currency: string|null,
 *     customer_name: string,
 *     address: array{name: string, line1: string, line2: string|null, postalCode: string|null, city: string|null, country: string|null, phone: null}|null,
 *     lines: array<int, array{transaction_id: int|null, listing_id: int|null, product_id: int|null, sku: string|null, title: string, quantity: int, unit_price_cents: int, choices: array<string, string>}>,
 *     subtotal_cents: int,
 *     shipping_cents: int,
 *     discount_cents: int,
 *     gift_wrap_cents: int,
 *     tax_cents: int,
 *     vat_cents: int,
 *     grandtotal_cents: int,
 *     buyer_message: string|null,
 *     gift_message: string|null,
 *     shipment: array{carrier: string|null, tracking_code: string|null}|null,
 *     created_at: Carbon|null,
 *     updated_at: Carbon|null,
 * }
 */
class EtsyReceiptMapper
{
    /**
     * @param  array<array-key, mixed>  $receipt  Um ShopReceipt.
     * @return MappedReceipt
     */
    public function map(array $receipt): array
    {
        $status = is_string($receipt['status'] ?? null) ? Str::lower($receipt['status']) : '';
        $lines = array_values(array_filter(array_map(
            fn (array $transaction): ?array => $this->line($transaction),
            EtsyPresenter::rows($receipt['transactions'] ?? null),
        )));

        $name = $this->text($receipt['name'] ?? null, 120) ?? 'Comprador da Etsy';

        return [
            'receipt_id' => (int) ($receipt['receipt_id'] ?? 0),
            'etsy_status' => Str::limit($status, 30, ''),
            'is_paid' => (bool) ($receipt['is_paid'] ?? false),
            'is_shipped' => (bool) ($receipt['is_shipped'] ?? false),
            // A Etsy escreve "canceled"; aceita-se tambem a outra grafia.
            'is_canceled' => in_array($status, ['canceled', 'cancelled'], true),
            'refund' => match ($status) {
                'fully refunded' => 'full',
                'partially refunded' => 'partial',
                default => null,
            },
            'currency' => EtsyPresenter::currency($receipt['grandtotal'] ?? null)
                ?? EtsyPresenter::currency($receipt['total_price'] ?? null),
            'customer_name' => $name,
            'address' => $this->address($receipt, $name),
            'lines' => $lines,
            // Preco x quantidade, linha a linha: e o que a encomenda mostra, e
            // nao depende de como a Etsy arredonda os totais dela.
            'subtotal_cents' => array_sum(array_map(fn (array $line): int => $line['unit_price_cents'] * $line['quantity'], $lines)),
            'shipping_cents' => $this->cents($receipt['total_shipping_cost'] ?? null),
            'discount_cents' => $this->cents($receipt['discount_amt'] ?? null),
            'gift_wrap_cents' => $this->cents($receipt['gift_wrap_price'] ?? null),
            'tax_cents' => $this->cents($receipt['total_tax_cost'] ?? null),
            'vat_cents' => $this->cents($receipt['total_vat_cost'] ?? null),
            'grandtotal_cents' => $this->cents($receipt['grandtotal'] ?? null),
            'buyer_message' => $this->text($receipt['message_from_buyer'] ?? null, 5000),
            'gift_message' => ($receipt['is_gift'] ?? false) || filled($receipt['gift_message'] ?? null)
                ? $this->text($receipt['gift_message'] ?? null, 5000)
                : null,
            'shipment' => $this->shipment($receipt),
            'created_at' => $this->time($receipt['create_timestamp'] ?? $receipt['created_timestamp'] ?? null),
            'updated_at' => $this->time($receipt['update_timestamp'] ?? $receipt['updated_timestamp'] ?? null),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $transaction
     * @return array{transaction_id: int|null, listing_id: int|null, product_id: int|null, sku: string|null, title: string, quantity: int, unit_price_cents: int, choices: array<string, string>}|null
     */
    private function line(array $transaction): ?array
    {
        $quantity = is_numeric($transaction['quantity'] ?? null) ? (int) $transaction['quantity'] : 0;

        if ($quantity < 1) {
            return null;
        }

        // O que o comprador escolheu (cor, tamanho) e o que escreveu (a
        // personalizacao), tudo pelo nome que a Etsy lhe mostrou.
        $choices = [];

        foreach (EtsyPresenter::rows($transaction['variations'] ?? null) as $variation) {
            $label = $this->text($variation['formatted_name'] ?? null, 80);
            $value = $this->text($variation['formatted_value'] ?? null, 500);

            if ($label !== null && $value !== null) {
                $choices[$label] = $value;
            }
        }

        return [
            'transaction_id' => $this->id($transaction['transaction_id'] ?? null),
            'listing_id' => $this->id($transaction['listing_id'] ?? null),
            'product_id' => $this->id($transaction['product_id'] ?? null),
            'sku' => $this->text($transaction['sku'] ?? null, 100),
            'title' => $this->text($transaction['title'] ?? null, 255) ?? 'Artigo da Etsy',
            'quantity' => $quantity,
            'unit_price_cents' => $this->cents($transaction['price'] ?? null),
            'choices' => $choices,
        ];
    }

    /**
     * A morada de envio, no formato das outras encomendas. Pode nao vir: a
     * Etsy so a da a algumas apps, em algumas regioes.
     *
     * @param  array<array-key, mixed>  $receipt
     * @return array{name: string, line1: string, line2: string|null, postalCode: string|null, city: string|null, country: string|null, phone: null}|null
     */
    private function address(array $receipt, string $name): ?array
    {
        $line1 = $this->text($receipt['first_line'] ?? null, 255);

        if ($line1 === null) {
            return null;
        }

        $city = $this->text($receipt['city'] ?? null, 120);
        $state = $this->text($receipt['state'] ?? null, 120);

        return [
            'name' => $name,
            'line1' => $line1,
            'line2' => $this->text($receipt['second_line'] ?? null, 255),
            'postalCode' => $this->text($receipt['zip'] ?? null, 20),
            // O estado/provincia, quando existe, vai com a localidade: as
            // moradas de ca nao tem campo para ele.
            'city' => $state === null || $city === null ? ($city ?? $state) : "{$city}, {$state}",
            'country' => $this->text($receipt['country_iso'] ?? null, 2),
            'phone' => null,
        ];
    }

    /**
     * O ultimo envio que a Etsy registou, se houver.
     *
     * @param  array<array-key, mixed>  $receipt
     * @return array{carrier: string|null, tracking_code: string|null}|null
     */
    private function shipment(array $receipt): ?array
    {
        $shipments = EtsyPresenter::rows($receipt['shipments'] ?? null);

        if ($shipments === []) {
            return null;
        }

        $last = end($shipments);

        return [
            'carrier' => $this->text($last['carrier_name'] ?? null, 100),
            'tracking_code' => $this->text($last['tracking_code'] ?? null, 100),
        ];
    }

    private function cents(mixed $money): int
    {
        return EtsyPresenter::cents($money) ?? 0;
    }

    private function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $max, '') : null;
    }

    private function time(mixed $timestamp): ?Carbon
    {
        return is_numeric($timestamp) && (int) $timestamp > 0 ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }
}
