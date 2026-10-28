<?php

namespace Tests\Feature\Stripe;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Os avisos do Stripe. Uma porta aberta para a internet: os testes sao sobre
 * quem nao entra, sobre cada aviso ser tratado uma so vez, e sobre o que cada
 * um faz a encomenda.
 */
class StripeWebhookTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeStripe();
    }

    private function order(): Order
    {
        $order = Order::factory()->create(['subtotal_cents' => 3000, 'shipping_cents' => 0, 'total_cents' => 3000]);
        OrderItem::factory()->for($order)->create(['unit_price_cents' => 3000, 'qty' => 1, 'line_total_cents' => 3000]);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function event(string $type, array $object): array
    {
        return ['id' => 'evt_'.Str::random(16), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function checkoutSession(Order $order, array $overrides = []): array
    {
        return [
            'id' => 'cs_test_'.Str::random(10),
            'object' => 'checkout.session',
            'client_reference_id' => (string) $order->id,
            'metadata' => ['order_id' => (string) $order->id],
            'payment_status' => 'paid',
            'amount_total' => $order->total_cents,
            'payment_intent' => 'pi_123',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return TestResponse<Response>
     */
    private function send(array $event, ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $body = (string) json_encode($event);
        $timestamp ??= now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret ?? self::stripeWebhookSecret());

        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_a_paid_session_marks_the_order_paid_and_moves_it_on(): void
    {
        $order = $this->order();
        $this->stripePaymentIntent('pi_123', ['latest_charge' => ['payment_method_details' => ['type' => 'mb_way']]]);

        $this->send($this->event('checkout.session.completed', $this->checkoutSession($order)))->assertNoContent();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('ready_to_ship', $order->status);
        $this->assertSame('mbway', $order->payment_method);
        $this->assertSame('pi_123', $order->stripe_payment_intent_id);
        $this->assertNotNull($order->paid_at);
    }

    public function test_the_same_event_twice_is_handled_once(): void
    {
        $order = $this->order();
        $this->stripePaymentIntent('pi_123');
        $event = $this->event('checkout.session.completed', $this->checkoutSession($order));

        $this->send($event)->assertNoContent();
        $this->send($event)->assertNoContent();

        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertNotNull(WebhookEvent::query()->first()?->processed_at);
        $this->assertSame(1, $order->statusHistories()->where('note', 'like', '%Pago pelo link%')->count());
    }

    public function test_a_wrong_signature_or_an_old_one_is_refused(): void
    {
        $order = $this->order();
        $event = $this->event('checkout.session.completed', $this->checkoutSession($order));

        $this->send($event, 'whsec_'.'outro-segredo-qualquer')->assertUnauthorized();
        $this->send($event, null, now()->subMinutes(10)->getTimestamp())->assertUnauthorized();
        $this->call('POST', '/webhooks/stripe', [], [], [], [], (string) json_encode($event))->assertUnauthorized();

        $this->assertSame('pending', $order->refresh()->payment_status);
        $this->assertSame(0, WebhookEvent::query()->count());
    }

    public function test_with_stripe_disabled_the_door_does_not_exist(): void
    {
        config(['stripe.enabled' => false]);

        $this->send($this->event('checkout.session.completed', []))->assertNotFound();
    }

    public function test_a_session_that_is_not_ours_touches_nothing(): void
    {
        $order = $this->order();

        $this->send($this->event('checkout.session.completed', $this->checkoutSession($order, ['metadata' => []])))->assertNoContent();

        $this->assertSame('pending', $order->refresh()->payment_status);
    }

    public function test_multibanco_waits_and_then_pays(): void
    {
        $order = $this->order();
        $this->stripePaymentIntent('pi_123', ['latest_charge' => ['payment_method_details' => ['type' => 'multibanco']]]);

        $this->send($this->event('checkout.session.completed', $this->checkoutSession($order, ['payment_status' => 'unpaid'])));

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('pi_123', $order->stripe_payment_intent_id);

        $this->send($this->event('checkout.session.async_payment_succeeded', $this->checkoutSession($order)));

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('multibanco', $order->payment_method);
    }

    public function test_a_failed_async_payment_does_not_cancel_and_frees_the_link(): void
    {
        $order = $this->order();
        $order->forceFill(['stripe_session_id' => 'cs_test_x', 'stripe_payment_intent_id' => 'pi_123'])->save();

        $this->send($this->event('checkout.session.async_payment_failed', $this->checkoutSession($order, ['id' => 'cs_test_x', 'payment_status' => 'unpaid'])));

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('pending_payment', $order->status);
        $this->assertNull($order->stripe_session_id);
        $this->assertNull($order->stripe_payment_intent_id);
    }

    public function test_a_different_amount_still_pays_but_leaves_a_warning(): void
    {
        $order = $this->order();
        $this->stripePaymentIntent('pi_123');

        $this->send($this->event('checkout.session.completed', $this->checkoutSession($order, ['amount_total' => 2500])));

        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertTrue($order->statusHistories()->where('note', 'like', '%Atenção: foram pagos 25,00 €%')->exists());
    }

    public function test_refunds_follow_the_charge(): void
    {
        $partial = $this->order();
        $partial->forceFill(['payment_status' => 'paid', 'status' => 'ready_to_ship', 'stripe_payment_intent_id' => 'pi_partial'])->save();
        $full = $this->order();
        $full->forceFill(['payment_status' => 'paid', 'status' => 'ready_to_ship', 'stripe_payment_intent_id' => 'pi_full'])->save();

        $this->send($this->event('charge.refunded', ['payment_intent' => 'pi_partial', 'amount' => 3000, 'amount_refunded' => 1000, 'refunded' => false]));
        $this->send($this->event('charge.refunded', ['payment_intent' => 'pi_full', 'amount' => 3000, 'amount_refunded' => 3000, 'refunded' => true]));

        $this->assertSame('partially_refunded', $partial->refresh()->payment_status);
        $this->assertSame('refunded', $full->refresh()->payment_status);
        $this->assertSame('refunded', $full->status);
    }

    public function test_when_stripe_does_not_answer_the_order_is_paid_anyway(): void
    {
        $order = $this->order();
        // Sem payment intent no fake: o Stripe responde 404 a pergunta do metodo.

        $this->send($this->event('checkout.session.completed', $this->checkoutSession($order)))->assertNoContent();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('bank_transfer', $order->payment_method);
    }

    public function test_a_refund_on_a_cancelled_order_does_not_fail_the_webhook(): void
    {
        $order = $this->order();
        $order->forceFill(['payment_status' => 'paid', 'status' => 'cancelled', 'stripe_payment_intent_id' => 'pi_cancelled'])->save();
        $event = $this->event('charge.refunded', ['payment_intent' => 'pi_cancelled', 'amount' => 3000, 'amount_refunded' => 3000, 'refunded' => true]);

        $this->send($event)->assertNoContent();

        $order->refresh();
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull(WebhookEvent::query()->find($event['id'])?->processed_at);
    }

    public function test_a_second_payment_on_a_paid_order_leaves_a_warning(): void
    {
        $order = $this->order();
        $order->forceFill(['payment_status' => 'paid', 'status' => 'ready_to_ship'])->save();

        $this->send($this->event('checkout.session.completed', $this->checkoutSession($order, ['payment_intent' => 'pi_extra'])))->assertNoContent();

        $this->assertTrue($order->statusHistories()->where('note', 'like', '%outro pagamento pelo Stripe (30,00 €, pi_extra)%')->exists());
        $this->assertNull($order->refresh()->stripe_payment_intent_id);
    }
}
