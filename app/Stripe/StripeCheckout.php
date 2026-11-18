<?php

namespace App\Stripe;

use App\Models\Order;
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
 * A sessao faz-se a partir de um Charge, a descricao do que se cobra; a
 * encomenda e uma das maneiras de o escrever (Charge::fromOrder).
 *
 * Uma so sessao viva por encomenda: antes de criar outra, expira-se a
 * anterior. Assim um cliente com o link aberto em duas abas nao paga duas
 * vezes.
 */
class StripeCheckout
{
    /** Ja ha um pagamento a caminho (uma referencia Multibanco por pagar). */
    public const PROCESSING = 'processing';

    /**
     * Mais do que o pior caso de um pedido: tres leituras de 15 s com pausas, e
     * depois expirar, cupao e sessao.
     */
    public const LOCK_SECONDS = 120;

    /** O minimo que o Stripe cobra em euros. */
    private const MINIMUM_CENTS = 50;

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
     * O tranco de quem abre uma sessao para esta encomenda. E o mesmo no /pagar
     * e no link gerado no backoffice.
     */
    public static function lockKey(Order $order): string
    {
        return "stripe-checkout:{$order->getKey()}";
    }

    /**
     * O endereco para onde mandar o cliente, ou PROCESSING quando a sessao
     * anterior ja foi submetida e o pagamento esta a caminho.
     */
    public function start(Order $order, string $returnUrl): string
    {
        // Dois cliques (ou duas abas) ao mesmo tempo liam a mesma sessao
        // anterior e criavam duas novas, ambas pagaveis. Um de cada vez.
        $lock = Cache::lock(self::lockKey($order), self::LOCK_SECONDS);

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
     * O pedido da sessao para pagar uma encomenda. Se houver desconto, cria o
     * cupao.
     *
     * @param  bool  $asLink  O link gerado no backoffice: tudo em `price_data` e os portes numa linha.
     * @param  string|null  $couponKey  A chave de idempotencia do cupao.
     * @return array<string, mixed>
     */
    public function payload(Order $order, string $returnUrl, bool $asLink = false, ?string $couponKey = null): array
    {
        return $this->sessionPayload(Charge::fromOrder($order, $returnUrl), $asLink, $couponKey);
    }

    /**
     * O pedido da sessao para cobrar uma descricao. Se houver desconto, cria
     * o cupao — so depois de conferir que tudo soma o total.
     *
     * @param  bool  $asLink  Tudo em `price_data` e os portes numa linha.
     * @param  string|null  $couponKey  A chave de idempotencia do cupao.
     * @return array<string, mixed>
     */
    public function sessionPayload(Charge $charge, bool $asLink = false, ?string $couponKey = null): array
    {
        $lines = [];
        $sum = 0;

        $currency = strtolower($charge->currency);

        foreach ($charge->lines as $chargeLine) {
            [$line, $amount] = $this->line($chargeLine, $currency, $asLink);
            $lines[] = $line;
            $sum += $amount;
        }

        $linesSum = $sum;

        $payload = [
            'mode' => 'payment',
            'client_reference_id' => $charge->reference,
            'metadata' => $charge->metadata,
            'payment_intent_data' => ['metadata' => $charge->metadata],
            'customer_email' => filled($charge->email) ? $charge->email : null,
            'locale' => 'auto',
            'success_url' => $charge->successUrl,
            'cancel_url' => $charge->cancelUrl,
            'custom_text' => ['submit' => ['message' => $charge->summary]],
        ];

        if ($charge->expiresAt !== null) {
            $payload['expires_at'] = $charge->expiresAt;
        }

        if ($charge->shippingCents > 0 && $asLink) {
            $lines[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $charge->shippingCents,
                    'product_data' => ['name' => 'Portes de envio'],
                ],
            ];
            $sum += $charge->shippingCents;
        } elseif ($charge->shippingCents > 0) {
            $payload['shipping_options'] = [[
                'shipping_rate_data' => [
                    'type' => 'fixed_amount',
                    'display_name' => Str::limit($charge->shippingName ?: 'Portes', 100, ''),
                    'fixed_amount' => ['amount' => $charge->shippingCents, 'currency' => $currency],
                ],
            ]];
            $sum += $charge->shippingCents;
        }

        // Antes do cupao: uma cobranca que nao soma nao deixa nada criado no
        // Stripe.
        $sum += $charge->extraCents - $charge->couponCents;

        // Um cupao do Stripe so desconta nas linhas, nao nos portes: um
        // desconto maior do que as linhas cobrava mais do que o total.
        if ($sum !== $charge->totalCents || $charge->totalCents <= 0 || $charge->couponCents > $linesSum) {
            throw new StripeException(sprintf(
                'As linhas da %s somam %d cêntimos e o total é %d. Nada foi cobrado.',
                $charge->label,
                $sum,
                $charge->totalCents,
            ));
        }

        if ($charge->totalCents < self::MINIMUM_CENTS) {
            throw new StripeException(sprintf(
                'O total da %s fica abaixo de 0,50 €, o mínimo do Stripe. Nada foi cobrado.',
                $charge->label,
            ));
        }

        if ($charge->extraCents > 0) {
            $lines[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $charge->extraCents,
                    'product_data' => ['name' => Str::limit($charge->extraName ?: 'Ajuste', 250, '')],
                ],
            ];
        }

        if ($charge->couponCents > 0) {
            // As linhas do Stripe nao podem ser negativas: um desconto e um
            // cupao, criado aqui e que so serve uma vez.
            $coupon = $this->stripe->post('coupons', [
                'amount_off' => $charge->couponCents,
                'currency' => $currency,
                'duration' => 'once',
                'max_redemptions' => 1,
                'name' => Str::limit($charge->couponName ?: 'Desconto', 40, ''),
            ], $couponKey);
            $payload['discounts'] = [['coupon' => (string) $coupon['id']]];
        }

        $payload['line_items'] = $lines;

        return $payload;
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function line(ChargeLine $chargeLine, string $currency, bool $priceData = false): array
    {
        $qty = $chargeLine->qty;
        $unit = intdiv($chargeLine->lineTotalCents, max($qty, 1));

        // Um total que nao se divide pela quantidade (nao acontece hoje, mas
        // um arredondamento futuro fa-lo-ia): vai como uma linha so.
        if ($unit * $qty !== $chargeLine->lineTotalCents) {
            [$qty, $unit] = [1, $chargeLine->lineTotalCents];
        }

        $variant = $chargeLine->variant;
        $product = $variant?->product;
        if ($variant !== null && $product !== null && Catalog::sellable($product) && $product->stripe_product_id !== null) {
            // O preco normal da variante: o Price dela, que os relatorios do
            // Stripe agrupam.
            if (! $priceData && $variant->active && $variant->stripe_price_id !== null && $variant->stripe_price_cents === $unit && $qty === $chargeLine->qty) {
                return [['price' => $variant->stripe_price_id, 'quantity' => $qty], $unit * $qty];
            }

            return [[
                'quantity' => $qty,
                'price_data' => ['currency' => $currency, 'unit_amount' => $unit, 'product' => $product->stripe_product_id],
            ], $unit * $qty];
        }

        // Linha livre, ou produto que o Stripe ainda nao conhece.
        return [[
            'quantity' => $qty,
            'price_data' => ['currency' => $currency, 'unit_amount' => $unit, 'product_data' => ['name' => Str::limit($chargeLine->name, 250, '')]],
        ], $unit * $qty];
    }
}
