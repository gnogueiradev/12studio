<?php

namespace App\Stripe;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * O que cada aviso do Stripe faz a uma encomenda.
 *
 *   checkout.session.completed            pago ja (cartao, MB WAY) → paga;
 *                                         por pagar (Multibanco) → fica a espera
 *   checkout.session.async_payment_succeeded   o Multibanco foi pago → paga
 *   checkout.session.async_payment_failed      nao foi → nota, e o link volta a servir
 *   charge.refunded                       reembolso total ou parcial
 *
 * Tudo passa pelo OrderService, que e a unica via de escrita de estados: e
 * ele que leva a encomenda paga para producao ou para expedicao, como quando
 * o dono a marca a mao.
 */
class StripeEvents
{
    /** Do tipo de pagamento do Stripe para o payment_method da encomenda. */
    private const METHODS = ['card' => 'card', 'multibanco' => 'multibanco', 'mb_way' => 'mbway'];

    public function __construct(
        private OrderService $orders,
        private StripeClient $stripe,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        $object = $event['data']['object'] ?? null;

        if (! is_array($object)) {
            return;
        }

        match ($event['type']) {
            'checkout.session.completed' => $this->sessionCompleted($object),
            'checkout.session.async_payment_succeeded' => $this->sessionPaid($object),
            'checkout.session.async_payment_failed' => $this->sessionFailed($object),
            'charge.refunded' => $this->refunded($object),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function sessionCompleted(array $session): void
    {
        if (($session['payment_status'] ?? null) === 'paid') {
            $this->sessionPaid($session);

            return;
        }

        $order = $this->orderOf($session);

        if ($order === null || $order->isPaid()) {
            return;
        }

        // Uma referencia Multibanco foi gerada: o cliente ja escolheu como
        // pagar, o dinheiro vem depois. O link mostra "em processamento".
        $order->forceFill([
            'stripe_session_id' => (string) $session['id'],
            'stripe_payment_intent_id' => $this->string($session['payment_intent'] ?? null),
        ])->save();

        $this->orders->notePayment($order, 'Stripe: pagamento iniciado, a aguardar confirmação.');
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function sessionPaid(array $session): void
    {
        $order = $this->orderOf($session);

        if ($order === null) {
            return;
        }

        $intent = $this->string($session['payment_intent'] ?? null);

        if ($order->isPaid()) {
            $this->paidTwice($order, $intent, (int) ($session['amount_total'] ?? 0));

            return;
        }

        $order->forceFill([
            'stripe_session_id' => (string) $session['id'],
            'stripe_payment_intent_id' => $intent,
        ])->save();

        // Como pagou (cartao, MB WAY, Multibanco) e um pedido ao Stripe: so
        // depois do commit, para nao prender o SQLite a espera da rede. Se o
        // Stripe nao responder, fica o metodo que ja estava.
        DB::afterCommit(function () use ($order, $intent): void {
            $method = $this->method($intent);

            if ($method !== null) {
                $order->forceFill(['payment_method' => $method])->save();
            }
        });

        $paid = (int) ($session['amount_total'] ?? 0);
        $note = 'Pago pelo link do Stripe.';

        // Pago e pago: o dinheiro chegou. Mas se a encomenda mudou depois de o
        // cliente abrir o pagamento, o dono tem de o saber.
        if ($paid !== $order->total_cents) {
            $note .= sprintf(' Atenção: foram pagos %s e a encomenda tem %s.', $this->euros($paid), $this->euros($order->total_cents));
        }

        $this->orders->setPaymentStatus($order, 'paid', null, $note);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function sessionFailed(array $session): void
    {
        $order = $this->orderOf($session);

        if ($order === null || $order->isPaid()) {
            return;
        }

        // Nao se cancela: o cliente pode tentar outra vez pelo mesmo link. A
        // sessao falhada e esquecida, para o link criar uma nova.
        $order->forceFill(['stripe_session_id' => null, 'stripe_payment_intent_id' => null])->save();

        $this->orders->notePayment($order, 'Stripe: o pagamento falhou ou expirou. O link de pagamento continua a servir.');
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function refunded(array $charge): void
    {
        $intent = $this->string($charge['payment_intent'] ?? null);

        if ($intent === null) {
            return;
        }

        $order = Order::query()->where('stripe_payment_intent_id', $intent)->first();

        if ($order === null) {
            return;
        }

        $full = ($charge['refunded'] ?? false) === true
            || (int) ($charge['amount_refunded'] ?? 0) >= (int) ($charge['amount'] ?? PHP_INT_MAX);

        $note = sprintf('Reembolso no Stripe: %s.', $this->euros((int) ($charge['amount_refunded'] ?? 0)));

        try {
            $this->orders->setPaymentStatus($order, $full ? 'refunded' : 'partially_refunded', null, $note);
        } catch (RuntimeException) {
            // Uma encomenda cancelada nao passa a "reembolsada" (o pipeline nao
            // sai de um estado terminal), mas o pagamento ja ficou marcado
            // como reembolsado. Sem isto, o aviso falhava para sempre e o
            // Stripe repetia-o durante tres dias.
            $this->orders->notePayment($order, $note.' A encomenda fica no estado em que estava.');
        }
    }

    /**
     * Chegou um pagamento a uma encomenda que ja estava paga: o dono marcou-a
     * a mao enquanto o cliente pagava pelo link, ou o cliente pagou duas
     * vezes. O dinheiro esta no Stripe e alguem tem de decidir o que fazer
     * com ele — fica no historico e no log.
     */
    private function paidTwice(Order $order, ?string $intent, int $cents): void
    {
        if ($intent !== null && $intent === $order->stripe_payment_intent_id) {
            return;
        }

        $note = sprintf(
            'Atenção: chegou outro pagamento pelo Stripe (%s, %s) a uma encomenda que já estava paga. Confirma no Dashboard e reembolsa se for a mais.',
            $this->euros($cents),
            $intent ?? 'sem referência',
        );

        $this->orders->notePayment($order, $note);
        Log::warning($note, ['order' => $order->order_number]);
    }

    /**
     * A encomenda da sessao: o client_reference_id, confirmado pela metadata
     * que o 12studio la pos. Uma sessao criada a mao no Dashboard nao tem
     * nem uma nem outra, e nao toca em encomenda nenhuma.
     *
     * @param  array<string, mixed>  $session
     */
    private function orderOf(array $session): ?Order
    {
        $reference = $this->string($session['client_reference_id'] ?? null);
        $metadata = $session['metadata'] ?? [];

        if ($reference === null || ! is_array($metadata) || ($metadata['order_id'] ?? null) !== $reference || ! ctype_digit($reference)) {
            return null;
        }

        return Order::query()->find((int) $reference);
    }

    /**
     * Como o cliente pagou. So um extra: se o Stripe nao responder, a
     * encomenda fica paga na mesma, com o metodo que tinha.
     */
    private function method(?string $intent): ?string
    {
        if ($intent === null) {
            return null;
        }

        try {
            $paymentIntent = $this->stripe->get("payment_intents/{$intent}", ['expand' => ['latest_charge']]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $type = $paymentIntent['latest_charge']['payment_method_details']['type'] ?? null;

        return is_string($type) ? (self::METHODS[$type] ?? 'other') : null;
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ').' €';
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
