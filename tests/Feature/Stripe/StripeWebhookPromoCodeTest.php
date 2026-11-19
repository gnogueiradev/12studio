<?php

namespace Tests\Feature\Stripe;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PromoCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O que o aviso do Stripe faz ao codigo de desconto e aos logs.
 */
class StripeWebhookPromoCodeTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    private PromoCode $promoCode;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeStripe();
        $this->promoCode = PromoCode::factory()->create(['code' => 'OLA5']);
    }

    private function order(): Order
    {
        $order = Order::factory()->create([
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'promo_code_id' => $this->promoCode->id,
            'promo_code' => 'OLA5',
            'discount_cents' => 500,
            'subtotal_cents' => 3000,
            'shipping_cents' => 0,
            'total_cents' => 2500,
        ]);
        OrderItem::factory()->for($order)->create(['unit_price_cents' => 3000, 'qty' => 1, 'line_total_cents' => 3000]);

        return $order->fresh();
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function completed(Order $order, array $session = []): array
    {
        return [
            'id' => 'evt_'.Str::random(16),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_'.Str::random(10),
                'object' => 'checkout.session',
                'client_reference_id' => (string) $order->id,
                'metadata' => ['order_id' => (string) $order->id],
                'payment_status' => 'paid',
                'amount_total' => $order->total_cents,
                'payment_intent' => 'pi_'.Str::random(10),
                ...$session,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function send(array $event): TestResponse
    {
        $body = (string) json_encode($event);
        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", self::stripeWebhookSecret());

        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }

    public function test_paying_records_the_use_once_even_if_the_event_repeats(): void
    {
        $order = $this->order();
        $event = $this->completed($order);

        $this->send($event)->assertNoContent();
        $this->send($event)->assertNoContent();

        $this->assertTrue($order->fresh()->isPaid());
        $this->assertSame(1, $this->promoCode->fresh()->uses_count);
        $this->assertSame(1, $this->promoCode->redemptions()->count());
    }

    public function test_a_new_event_for_the_same_session_does_not_record_again(): void
    {
        $order = $this->order();

        $this->send($this->completed($order, ['id' => 'cs_test_igual', 'payment_intent' => 'pi_igual']))->assertNoContent();
        $this->send($this->completed($order, ['id' => 'cs_test_igual', 'payment_intent' => 'pi_igual']))->assertNoContent();

        $this->assertSame(1, $this->promoCode->fresh()->uses_count);
    }

    public function test_a_multibanco_reference_does_not_spend_the_code_until_it_is_paid(): void
    {
        $order = $this->order();

        $this->send($this->completed($order, ['payment_status' => 'unpaid', 'payment_intent' => 'pi_mb']))->assertNoContent();

        $this->assertFalse($order->fresh()->isPaid());
        $this->assertSame(0, $this->promoCode->fresh()->uses_count);
    }

    public function test_a_different_total_logs_a_warning_and_does_not_change_the_order(): void
    {
        $log = Log::spy();
        $log->shouldReceive('channel')->with('api')->andReturnSelf();
        $order = $this->order();

        $this->send($this->completed($order, ['id' => 'cs_test_dif', 'amount_total' => 2400]))->assertNoContent();

        $log->shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => ($context['amount_total'] ?? null) === 2400
            && ($context['total_cents'] ?? null) === 2500
            && ($context['order_id'] ?? null) === $order->id
            && ($context['session_id'] ?? null) === 'cs_test_dif');

        $fresh = $order->fresh();
        $this->assertTrue($fresh->isPaid());
        $this->assertSame(2500, $fresh->total_cents);
    }

    public function test_an_equal_total_logs_no_warning(): void
    {
        $log = Log::spy();
        $log->shouldReceive('channel')->with('api')->andReturnSelf();

        $this->send($this->completed($this->order()))->assertNoContent();

        $log->shouldNotHaveReceived('warning');
    }

    public function test_the_log_marks_processed_and_duplicate_without_the_payload(): void
    {
        $log = Log::spy();
        $log->shouldReceive('channel')->with('api')->andReturnSelf();
        $event = $this->completed($this->order());

        $this->send($event);
        $this->send($event);

        foreach (['processed', 'duplicate'] as $result) {
            $log->shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []): bool => ($context['result'] ?? null) === $result
                && ($context['event_id'] ?? null) === $event['id']
                && ($context['session_id'] ?? null) === $event['data']['object']['id']
                && ! array_key_exists('data', $context));
        }
    }

    public function test_a_payment_on_a_cancelled_order_warns_and_leaves_a_note(): void
    {
        $log = Log::spy();
        $log->shouldReceive('channel')->with('api')->andReturnSelf();
        $order = $this->order();
        $order->update(['status' => 'cancelled']);

        $this->send($this->completed($order, ['id' => 'cs_test_fechada']))->assertNoContent();

        $log->shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => ($context['status'] ?? null) === 'cancelled'
            && ($context['session_id'] ?? null) === 'cs_test_fechada');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertTrue($order->statusHistories()->where('note', 'like', '%reembolsa%')->exists());
    }

    public function test_a_wrong_signature_changes_nothing(): void
    {
        $order = $this->order();
        $body = (string) json_encode($this->completed($order));

        $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.now()->getTimestamp().',v1=errada',
        ], $body)->assertUnauthorized();

        $this->assertFalse($order->fresh()->isPaid());
        $this->assertSame(0, $this->promoCode->fresh()->uses_count);
    }
}
