<?php

namespace App\Stripe;

use App\Models\Order;
use App\Models\OrderItem;
use App\Stripe\StripeCatalog as Catalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A Checkout Session do Stripe para pagar uma encomenda do backoffice.
 *
 * Cobra-se SEMPRE o que a encomenda diz, nao o catalogo: uma linha com o
 * preco normal da variante usa o Price dela (os relatorios do Stripe mostram
 * a variante), uma linha com o preco mudado leva um preco so dela, ligado ao
 * mesmo Product. Os portes vao como portes e o ajuste como linha (extra) ou
 * cupao de uso unico (desconto). Antes de pedir, confere-se que tudo soma o
 * total da encomenda — se nao somar, nao se cobra.
 *
 * Uma so sessao viva por encomenda: antes de criar outra, expira-se a
 * anterior. Assim um cliente com o link aberto em duas abas nao paga duas
 * vezes.
 */
class StripeCheckout
{
    /** Ja ha um pagamento a caminho (uma referencia Multibanco por pagar). */
    public const PROCESSING = 'processing';

    public function __construct(
        private StripeClient $stripe,
    ) {}

    /**
     * Quando a encomenda se pode pagar pelo link.
     */
    public static function payable(Order $order): bool
    {
        return $order->payment_status === 'pending'
            && ! in_array($order->status, ['cancelled', 'refunded'], true);
    }

    /**
     * O endereco para onde mandar o cliente, ou PROCESSING quando a sessao
     * anterior ja foi submetida e o pagamento esta a caminho.
     */
    public function start(Order $order, string $returnUrl): string
    {
        // Dois cliques (ou duas abas) ao mesmo tempo liam a mesma sessao
        // anterior e criavam duas novas, ambas pagaveis. Um de cada vez.
        $lock = Cache::lock("stripe-checkout:{$order->getKey()}", 30);

        if (! $lock->get()) {
            throw new StripeException('Já está a ser aberto um pagamento para esta encomenda.');
        }

        try {
            return $this->startLocked($order->refresh(), $returnUrl);
        } finally {
            $lock->release();
        }
    }

    private function startLocked(Order $order, string $returnUrl): string
    {
        if (! self::payable($order)) {
            throw new StripeException('Esta encomenda já não está por pagar.');
        }

        if ($order->stripe_session_id !== null) {
            $previous = $this->stripe->get("checkout/sessions/{$order->stripe_session_id}");

            if (($previous['status'] ?? null) === 'complete') {
                return self::PROCESSING;
            }

            if (($previous['status'] ?? null) === 'open') {
                $this->stripe->post("checkout/sessions/{$order->stripe_session_id}/expire");
            }
        }

        $session = $this->stripe->post('checkout/sessions', $this->payload($order, $returnUrl));

        $order->forceFill(['stripe_session_id' => (string) $session['id']])->save();

        return (string) $session['url'];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Order $order, string $returnUrl): array
    {
        $order->loadMissing('items.variant.product');

        $lines = [];
        $sum = 0;

        $currency = strtolower($order->currency);

        foreach ($order->items as $item) {
            [$line, $amount] = $this->line($item, $currency);
            $lines[] = $line;
            $sum += $amount;
        }

        $linesSum = $sum;
        $metadata = ['order_id' => (string) $order->getKey(), 'order_number' => $order->order_number];

        $payload = [
            'mode' => 'payment',
            'client_reference_id' => (string) $order->getKey(),
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'customer_email' => filled($order->email) ? $order->email : null,
            'locale' => 'auto',
            'success_url' => $returnUrl.'?pago=1',
            'cancel_url' => $returnUrl,
            'custom_text' => ['submit' => ['message' => $this->summary($order)]],
        ];

        if ($order->shipping_cents > 0) {
            $payload['shipping_options'] = [[
                'shipping_rate_data' => [
                    'type' => 'fixed_amount',
                    'display_name' => Str::limit($order->shipping_method_name ?: 'Portes', 100, ''),
                    'fixed_amount' => ['amount' => $order->shipping_cents, 'currency' => $currency],
                ],
            ]];
            $sum += $order->shipping_cents;
        }

        // Antes do cupao: uma encomenda que nao soma nao deixa nada criado no
        // Stripe.
        $sum += $order->adjustment_cents;

        // Um cupao do Stripe so desconta nas linhas, nao nos portes: um
        // desconto maior do que as linhas cobrava mais do que o total.
        if ($sum !== $order->total_cents || $order->total_cents <= 0 || -$order->adjustment_cents > $linesSum) {
            throw new StripeException(sprintf(
                'As linhas da encomenda %s somam %d cêntimos e o total é %d. Nada foi cobrado.',
                $order->order_number,
                $sum,
                $order->total_cents,
            ));
        }

        if ($order->adjustment_cents > 0) {
            $lines[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $order->adjustment_cents,
                    'product_data' => ['name' => Str::limit($order->adjustment_reason ?: 'Ajuste', 250, '')],
                ],
            ];
        } elseif ($order->adjustment_cents < 0) {
            // As linhas do Stripe nao podem ser negativas: um desconto e um
            // cupao, criado aqui e que so serve uma vez.
            $coupon = $this->stripe->post('coupons', [
                'amount_off' => -$order->adjustment_cents,
                'currency' => $currency,
                'duration' => 'once',
                'max_redemptions' => 1,
                'name' => Str::limit($order->adjustment_reason ?: 'Desconto', 40, ''),
            ]);
            $payload['discounts'] = [['coupon' => (string) $coupon['id']]];
        }

        $payload['line_items'] = $lines;

        return $payload;
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function line(OrderItem $item, string $currency): array
    {
        $qty = $item->qty;
        $unit = intdiv($item->line_total_cents, max($qty, 1));

        // Um total que nao se divide pela quantidade (nao acontece hoje, mas
        // um arredondamento futuro fa-lo-ia): vai como uma linha so.
        if ($unit * $qty !== $item->line_total_cents) {
            [$qty, $unit] = [1, $item->line_total_cents];
        }

        $variant = $item->variant;
        $product = $variant?->product;
        if ($variant !== null && $product !== null && Catalog::sellable($product) && $product->stripe_product_id !== null) {
            // O preco normal da variante: o Price dela, que os relatorios do
            // Stripe agrupam.
            if ($variant->active && $variant->stripe_price_id !== null && $variant->stripe_price_cents === $unit && $qty === $item->qty) {
                return [['price' => $variant->stripe_price_id, 'quantity' => $qty], $unit * $qty];
            }

            return [[
                'quantity' => $qty,
                'price_data' => ['currency' => $currency, 'unit_amount' => $unit, 'product' => $product->stripe_product_id],
            ], $unit * $qty];
        }

        // Linha livre, ou produto que o Stripe ainda nao conhece.
        $name = trim($item->product_name.($item->variant_label ? " ({$item->variant_label})" : ''));

        return [[
            'quantity' => $qty,
            'price_data' => ['currency' => $currency, 'unit_amount' => $unit, 'product_data' => ['name' => Str::limit($name, 250, '')]],
        ], $unit * $qty];
    }

    /**
     * O Stripe mostra o nome do Product, nao o da variante: duas linhas
     * "Vaso" nao dizem qual e o grande. O resumo vai por baixo do botao de
     * pagar, com o numero da encomenda.
     */
    private function summary(Order $order): string
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
