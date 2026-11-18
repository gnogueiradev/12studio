<?php

namespace App\Stripe;

use App\Models\Checkout;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Variant;
use Illuminate\Support\Str;

/**
 * O que se pede ao Stripe para cobrar, ja sem saber de onde vem: uma
 * encomenda do backoffice ou uma compra da loja que ainda nao e encomenda.
 * O StripeCheckout faz a sessao a partir disto, e as regras de la (somar
 * certo, o minimo de 0,50 €) valem igual para todas.
 */
final readonly class Charge
{
    /**
     * @param  list<ChargeLine>  $lines
     * @param  array<string, string>  $metadata
     * @param  string  $label  Para os erros: "encomenda 2026-0042", "compra 17".
     * @param  string  $summary  O texto por baixo do botao de pagar.
     * @param  int  $extraCents  Um ajuste positivo, que vai como linha.
     * @param  int  $couponCents  O desconto e um ajuste negativo, juntos num cupao.
     * @param  int|null  $expiresAt  Timestamp.
     */
    public function __construct(
        public string $reference,
        public array $metadata,
        public string $label,
        public string $summary,
        public string $currency,
        public ?string $email,
        public array $lines,
        public int $shippingCents,
        public ?string $shippingName,
        public int $extraCents,
        public ?string $extraName,
        public int $couponCents,
        public ?string $couponName,
        public int $totalCents,
        public string $successUrl,
        public string $cancelUrl,
        public ?int $expiresAt = null,
    ) {
        // O sessionPayload soma o extra e o cupao sempre, mas so os manda ao
        // Stripe quando sao positivos: um negativo passava na conferencia da
        // soma e cobrava outro valor. Uma descricao assim nem chega a existir.
        $negative = min($shippingCents, $extraCents, $couponCents, $totalCents) < 0;

        foreach ($lines as $line) {
            $negative = $negative || $line->qty < 1 || $line->lineTotalCents < 0;
        }

        if ($negative) {
            throw new StripeException("A cobrança da {$label} tem um valor negativo ou uma linha sem quantidade. Nada foi cobrado.");
        }
    }

    /**
     * O link de pagamento de uma encomenda: cobra-se o que ela diz. A
     * validade do link gerado no backoffice e posta pelo
     * StripePaymentLinkService, nao aqui.
     */
    public static function fromOrder(Order $order, string $returnUrl): self
    {
        $order->loadMissing('items.variant.product');

        $lines = array_values($order->items->map(fn (OrderItem $item): ChargeLine => new ChargeLine(
            name: trim($item->product_name.($item->variant_label ? " ({$item->variant_label})" : '')),
            qty: $item->qty,
            lineTotalCents: $item->line_total_cents,
            variant: $item->variant,
        ))->all());

        return new self(
            reference: (string) $order->getKey(),
            metadata: ['order_id' => (string) $order->getKey(), 'order_number' => $order->order_number],
            label: "encomenda {$order->order_number}",
            summary: self::summary($order),
            currency: $order->currency,
            email: $order->email,
            lines: $lines,
            shippingCents: $order->shipping_cents,
            shippingName: $order->shipping_method_name,
            extraCents: max(0, $order->adjustment_cents),
            extraName: $order->adjustment_reason,
            // O Stripe so aceita um cupao por sessao: o codigo e um ajuste
            // negativo vao juntos.
            couponCents: $order->discount_cents + max(0, -$order->adjustment_cents),
            couponName: $order->promo_code ?: $order->adjustment_reason,
            totalCents: $order->total_cents,
            successUrl: $returnUrl.'?pago=1',
            cancelUrl: $returnUrl,
        );
    }

    /**
     * Uma compra da loja: cobra-se o retrato congelado no "Pagar", nunca o
     * carrinho, que pode ter mudado entretanto. Cada linha leva a variante
     * (com o produto), para a que vai ao preco normal usar o Price dela, como
     * no link de pagamento.
     */
    public static function fromCheckout(Checkout $checkout, string $successUrl, string $cancelUrl): self
    {
        $snapshot = $checkout->items_snapshot;

        $variants = Variant::query()
            ->with('product')
            ->whereIn('id', array_filter(array_column($snapshot, 'variant_id')))
            ->get()
            ->keyBy('id');

        $lines = array_map(fn (array $line): ChargeLine => new ChargeLine(
            name: self::lineName((string) $line['product_name'], $line['variant_label'] ?? null),
            qty: (int) $line['qty'],
            lineTotalCents: (int) $line['line_total_cents'],
            variant: $variants->get($line['variant_id'] ?? 0),
        ), array_values($snapshot));

        $summary = array_map(fn (array $line): string => sprintf(
            '%d× %s',
            $line['qty'],
            self::lineName((string) $line['product_name'], $line['variant_label'] ?? null),
        ), $snapshot);

        // A pagina do Stripe fala a lingua de quem compra: a do "Pagar".
        $locale = $checkout->locale ?? (string) config('locales.default');

        return new self(
            reference: 'checkout-'.$checkout->id,
            metadata: ['checkout_id' => (string) $checkout->id],
            label: "compra {$checkout->id}",
            summary: self::limitSummary(trans('store.checkout.stripe_summary', ['lines' => implode('; ', $summary)], $locale)),
            currency: 'eur',
            email: $checkout->customer['email'] ?? null,
            lines: $lines,
            shippingCents: $checkout->shipping_cents,
            shippingName: trans('store.checkout.stripe_shipping', [], $locale),
            extraCents: 0,
            extraName: null,
            couponCents: $checkout->discount_cents,
            couponName: $checkout->promo_code,
            totalCents: $checkout->total_cents,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            expiresAt: $checkout->expires_at?->getTimestamp(),
        );
    }

    /**
     * O Stripe mostra o nome do Product, nao o da variante: duas linhas
     * "Vaso" nao dizem qual e o grande. O resumo vai por baixo do botao de
     * pagar, com o numero da encomenda.
     */
    private static function summary(Order $order): string
    {
        $lines = $order->items->map(fn (OrderItem $item): string => sprintf(
            '%d× %s%s',
            $item->qty,
            $item->product_name,
            $item->variant_label ? " ({$item->variant_label})" : '',
        ));

        return self::limitSummary("Encomenda {$order->order_number}: ".$lines->implode('; ').'.');
    }

    /** O Stripe aceita 1200 caracteres, e o Str::limit poe as reticencias por cima. */
    private static function limitSummary(string $summary): string
    {
        return Str::limit($summary, 1197);
    }

    private static function lineName(string $productName, mixed $variantLabel): string
    {
        return trim($productName.(is_string($variantLabel) && $variantLabel !== '' ? " ({$variantLabel})" : ''));
    }
}
