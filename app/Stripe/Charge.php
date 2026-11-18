<?php

namespace App\Stripe;

use App\Models\Order;
use App\Models\OrderItem;
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
    ) {}

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

        // O Stripe aceita 1200 caracteres, e o Str::limit poe as reticencias por cima.
        return Str::limit("Encomenda {$order->order_number}: ".$lines->implode('; ').'.', 1197);
    }
}
