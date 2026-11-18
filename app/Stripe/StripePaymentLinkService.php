<?php

namespace App\Stripe;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * O botao "Gerar link de pagamento": uma Checkout Session do Stripe para copiar
 * e mandar por DM, por 24 horas no maximo.
 *
 * Partilha com o /pagar o tranco e a regra de uma so sessao viva por encomenda:
 * gerar outra vez devolve a mesma enquanto estiver aberta e a encomenda nao tiver
 * mudado, e expira-a se tiver. Duas sessoes abertas eram dois pagamentos possiveis.
 */
class StripePaymentLinkService
{
    /** O Stripe aceita ate 24 horas; a margem cobre a diferenca entre relogios. */
    private const LIFETIME_SECONDS = 86100;

    /** Uma sessao com menos do que isto nao se volta a dar: chegava expirada ao DM. */
    private const REUSE_MIN_SECONDS = 43200;

    /**
     * A validade arredonda para baixo a esta janela. O Stripe recusa a mesma
     * chave de idempotencia com parametros diferentes: com um `expires_at` a
     * mudar a cada segundo, repetir um pedido perdido dava erro em vez de devolver
     * a sessao ja criada.
     */
    private const EXPIRY_WINDOW_SECONDS = 300;

    public function __construct(
        private StripeClient $stripe,
        private StripeCheckout $checkout,
    ) {}

    public function create(Order $order): PaymentLink
    {
        $lock = Cache::lock(StripeCheckout::lockKey($order), StripeCheckout::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new StripeException('Já está a ser aberto um pagamento para esta encomenda.');
        }

        try {
            $link = $this->createLocked($order->refresh());
        } finally {
            $lock->release();
        }

        Log::channel('api')->info('Link de pagamento gerado.', [
            'order_id' => $order->getKey(),
            'session_id' => $link->sessionId,
            'amount_total' => $link->amountTotalCents,
            'expires_at' => $link->expiresAt,
            'reused' => $link->reused,
        ]);

        return $link;
    }

    private function createLocked(Order $order): PaymentLink
    {
        if ($order->isPaid()) {
            throw new StripeException('Esta encomenda já está paga.');
        }

        if (! StripeCheckout::payable($order)) {
            throw new StripeException('Esta encomenda já não está por pagar.');
        }

        $version = (string) $order->updated_at?->getTimestamp();

        if ($order->stripe_session_id !== null) {
            $previous = $this->stripe->get("checkout/sessions/{$order->stripe_session_id}");

            if (($previous['status'] ?? null) === 'complete') {
                throw new StripeException('Já há um pagamento a caminho para esta encomenda.');
            }

            if (($previous['status'] ?? null) === 'open') {
                $remaining = (int) ($previous['expires_at'] ?? 0) - now()->getTimestamp();

                if (($previous['metadata']['order_version'] ?? null) === $version && $remaining >= self::REUSE_MIN_SECONDS) {
                    return $this->link($previous, reused: true);
                }

                $this->stripe->post("checkout/sessions/{$order->stripe_session_id}/expire");
            }
        }

        $expiresAt = intdiv(now()->getTimestamp() + self::LIFETIME_SECONDS, self::EXPIRY_WINDOW_SECONDS) * self::EXPIRY_WINDOW_SECONDS;
        $key = "order-{$order->getKey()}-{$version}-{$expiresAt}";

        $payload = $this->checkout->payload(
            $order,
            route('payment-link.show', $order->guest_access_token),
            asLink: true,
            couponKey: "{$key}-coupon",
        );

        $payload['expires_at'] = $expiresAt;
        $payload['metadata']['order_version'] = $version;

        $session = $this->stripe->post('checkout/sessions', $payload, "{$key}-session");

        // Sem mexer no updated_at: e dele que sai a chave, e o proximo clique
        // tem de reconhecer esta sessao em vez de criar outra.
        $order->timestamps = false;
        $order->forceFill(['stripe_session_id' => (string) $session['id']])->save();
        $order->timestamps = true;

        return $this->link($session, reused: false);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function link(array $session, bool $reused): PaymentLink
    {
        return new PaymentLink(
            url: (string) $session['url'],
            sessionId: (string) $session['id'],
            amountTotalCents: (int) ($session['amount_total'] ?? 0),
            expiresAt: (int) ($session['expires_at'] ?? 0),
            reused: $reused,
        );
    }
}
