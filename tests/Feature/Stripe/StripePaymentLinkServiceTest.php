<?php

namespace Tests\Feature\Stripe;

use App\Jobs\ExpireStripeSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\OrderService;
use App\Stripe\StripeException;
use App\Stripe\StripePaymentLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O botao "Gerar link de pagamento". O fake recusa o que o Stripe recusa: a mesma
 * chave de idempotencia com outros parametros e validades fora das 24 horas.
 */
class StripePaymentLinkServiceTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $overrides = []): Order
    {
        $order = Order::factory()->create([
            'email' => 'ana@example.com',
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'subtotal_cents' => 3000,
            'shipping_cents' => 0,
            'total_cents' => 3000,
            ...$overrides,
        ]);
        OrderItem::factory()->for($order)->create([
            'variant_id' => null,
            'product_name' => 'Vaso',
            'unit_price_cents' => 1500,
            'qty' => 2,
            'line_total_cents' => 3000,
        ]);

        return $order->fresh();
    }

    private function links(): StripePaymentLinkService
    {
        return app(StripePaymentLinkService::class);
    }

    public function test_it_creates_a_session_with_everything_the_order_says(): void
    {
        $promoCode = PromoCode::factory()->create(['code' => 'OLA5']);
        $order = $this->order([
            'shipping_cents' => 450,
            'promo_code_id' => $promoCode->id,
            'promo_code' => 'OLA5',
            'discount_cents' => 500,
            'total_cents' => 2950,
        ]);

        $link = $this->links()->create($order);

        $this->assertSame(2950, $link->amountTotalCents);
        $this->assertStringStartsWith('https://checkout.stripe.test/', $link->url);
        $this->assertSame($link->sessionId, $order->fresh()->stripe_session_id);

        $request = $this->stripe['sessions'][$link->sessionId]['request'];

        $this->assertSame((string) $order->id, $request['client_reference_id']);
        $this->assertSame((string) $order->id, $request['metadata']['order_id']);
        $this->assertSame('1500', $request['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('Portes de envio', $request['line_items'][1]['price_data']['product_data']['name']);
        $this->assertSame('450', $request['line_items'][1]['price_data']['unit_amount']);
        $this->assertArrayNotHasKey('allow_promotion_codes', $request);
        $this->assertArrayNotHasKey('shipping_options', $request);

        $coupon = $this->stripe['coupons'][$request['discounts'][0]['coupon']];

        $this->assertSame('500', $coupon['amount_off']);
        $this->assertSame('eur', $coupon['currency']);
        $this->assertSame('once', $coupon['duration']);
        $this->assertSame('1', $coupon['max_redemptions']);
        $this->assertSame('OLA5', $coupon['name']);

        $this->assertLessThanOrEqual(now()->addDay()->getTimestamp(), (int) $request['expires_at']);
    }

    public function test_free_shipping_has_no_shipping_line(): void
    {
        $link = $this->links()->create($this->order());

        $this->assertCount(1, $this->stripe['sessions'][$link->sessionId]['request']['line_items']);
    }

    public function test_the_idempotency_key_starts_with_the_order_and_its_update(): void
    {
        $order = $this->order();

        $this->links()->create($order);

        $key = $this->stripeCallsTo('POST', 'checkout/sessions')[0]['idempotency'];

        $this->assertStringStartsWith("order-{$order->id}-{$order->updated_at->getTimestamp()}-", (string) $key);
    }

    public function test_the_coupon_and_the_session_do_not_share_a_key(): void
    {
        $order = $this->order(['discount_cents' => 500, 'promo_code' => 'OLA5', 'total_cents' => 2500]);

        $this->links()->create($order);

        $this->assertNotSame(
            $this->stripeCallsTo('POST', 'coupons')[0]['idempotency'],
            $this->stripeCallsTo('POST', 'checkout/sessions')[0]['idempotency'],
        );
    }

    public function test_generating_again_returns_the_same_open_session(): void
    {
        $order = $this->order();

        $first = $this->links()->create($order);
        $second = $this->links()->create($order->fresh());

        $this->assertSame($first->url, $second->url);
        $this->assertTrue($second->reused);
        $this->assertCount(1, $this->stripeCallsTo('POST', 'checkout/sessions'));
    }

    public function test_saving_the_session_does_not_touch_the_order_version(): void
    {
        $order = $this->order();
        $before = $order->updated_at->getTimestamp();
        $this->travel(5)->minutes();

        $this->links()->create($order);

        $this->assertSame($before, Order::query()->findOrFail($order->id)->updated_at->getTimestamp());

        $this->travel(5)->minutes();

        $this->assertTrue($this->links()->create(Order::query()->findOrFail($order->id))->reused);
    }

    public function test_a_session_about_to_expire_is_replaced_instead_of_reused(): void
    {
        $order = $this->order();
        $first = $this->links()->create($order);

        $this->travel(13)->hours();

        $second = $this->links()->create($order->fresh());

        $this->assertFalse($second->reused);
        $this->assertNotSame($first->sessionId, $second->sessionId);
        $this->assertSame('expired', $this->stripe['sessions'][$first->sessionId]['status']);
    }

    public function test_paying_by_hand_closes_the_open_link(): void
    {
        $order = $this->order();
        $link = $this->links()->create($order);

        app(OrderService::class)->setPaymentStatus($order->fresh(), 'paid');

        $this->assertSame('expired', $this->stripe['sessions'][$link->sessionId]['status']);
    }

    public function test_cancelling_closes_the_open_link(): void
    {
        $order = $this->order();
        $link = $this->links()->create($order);

        app(OrderService::class)->transitionOrder($order->fresh(), 'cancelled');

        $this->assertSame('expired', $this->stripe['sessions'][$link->sessionId]['status']);
    }

    public function test_changing_the_total_closes_the_open_link(): void
    {
        $order = $this->order();
        $link = $this->links()->create($order);

        app(OrderService::class)->setAdjustment($order->fresh(), -500, 'Combinado');

        $this->assertSame('expired', $this->stripe['sessions'][$link->sessionId]['status']);
    }

    public function test_closing_the_link_goes_through_the_queue_and_never_holds_up_the_payment(): void
    {
        Queue::fake();
        $order = $this->order();
        $this->links()->create($order);

        app(OrderService::class)->setPaymentStatus($order->fresh(), 'paid');

        $this->assertTrue($order->fresh()->isPaid());
        Queue::assertPushed(ExpireStripeSession::class);
    }

    public function test_the_job_only_touches_the_session_it_was_given(): void
    {
        $order = $this->order();
        $link = $this->links()->create($order);
        $this->stripe['sessions'][$link->sessionId]['status'] = 'complete';

        ExpireStripeSession::dispatchSync($order->id, $link->sessionId);

        $this->assertSame('complete', $this->stripe['sessions'][$link->sessionId]['status']);
        $this->assertSame([], $this->stripeCallsTo('POST', 'checkout/sessions/*/expire'));
    }

    public function test_an_expired_link_gets_a_new_session_from_the_same_order(): void
    {
        $order = $this->order();
        $first = $this->links()->create($order);

        $this->stripe['sessions'][$first->sessionId]['status'] = 'expired';
        $this->travel(25)->hours();

        $second = $this->links()->create($order->fresh());

        $this->assertNotSame($first->sessionId, $second->sessionId);
        $this->assertSame($second->sessionId, $order->fresh()->stripe_session_id);
    }

    public function test_a_changed_order_expires_the_open_session_first(): void
    {
        $order = $this->order();
        $first = $this->links()->create($order);

        $this->travel(1)->minutes();
        $order->fresh()->update(['total_cents' => 3000, 'admin_note' => 'mudou']);

        $second = $this->links()->create($order->fresh());

        $this->assertSame('expired', $this->stripe['sessions'][$first->sessionId]['status']);
        $this->assertNotSame($first->sessionId, $second->sessionId);
    }

    public function test_a_retry_after_a_lost_answer_gets_the_same_session(): void
    {
        $order = $this->order();
        $this->links()->create($order);

        // A resposta perdeu-se: o Stripe criou a sessao, mas ca nao ficou gravada.
        Order::query()->whereKey($order->id)->toBase()->update(['stripe_session_id' => null]);

        $this->links()->create($order->fresh());

        $this->assertCount(1, $this->stripe['sessions']);
    }

    public function test_a_paid_order_is_refused(): void
    {
        $order = $this->order(['payment_status' => 'paid', 'status' => 'in_production']);

        $this->expectException(StripeException::class);
        $this->expectExceptionMessage('já está paga');

        $this->links()->create($order);
    }

    public function test_a_session_with_a_payment_on_its_way_is_refused(): void
    {
        $order = $this->order();
        $link = $this->links()->create($order);

        $this->stripe['sessions'][$link->sessionId]['status'] = 'complete';

        $this->expectException(StripeException::class);

        $this->links()->create($order->fresh());
    }

    public function test_a_total_below_the_stripe_minimum_is_refused_before_anything_is_created(): void
    {
        $order = $this->order(['discount_cents' => 2970, 'promo_code' => 'QUASE', 'total_cents' => 30]);

        try {
            $this->links()->create($order);
            $this->fail('Devia ter recusado.');
        } catch (StripeException $exception) {
            $this->assertStringContainsString('0,50 €', $exception->getMessage());
        }

        $this->assertSame([], $this->stripe['coupons']);
        $this->assertSame([], $this->stripe['sessions']);
    }

    public function test_the_code_and_a_negative_adjustment_go_in_one_coupon(): void
    {
        $order = $this->order([
            'discount_cents' => 500,
            'promo_code' => 'OLA5',
            'adjustment_cents' => -200,
            'total_cents' => 2300,
        ]);

        $link = $this->links()->create($order);

        $this->assertSame(2300, $link->amountTotalCents);
        $this->assertCount(1, $this->stripe['coupons']);
        $this->assertSame('700', array_values($this->stripe['coupons'])[0]['amount_off']);
    }

    public function test_the_log_has_the_context_and_no_personal_data(): void
    {
        $log = Log::spy();
        $log->shouldReceive('channel')->with('api')->andReturnSelf();
        $order = $this->order();

        $link = $this->links()->create($order);

        $log->shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $context['order_id'] === $order->id
            && $context['session_id'] === $link->sessionId
            && $context['amount_total'] === 3000
            && $context['expires_at'] === $link->expiresAt
            && ! array_key_exists('email', $context)
            && ! array_key_exists('url', $context));
    }

    public function test_the_button_returns_the_url_to_copy(): void
    {
        $order = $this->order();

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('admin.encomendas.link-pagamento', $order))
            ->assertOk()
            ->assertJsonStructure(['url', 'expiresAt']);
    }

    public function test_the_button_says_why_it_refused(): void
    {
        $order = $this->order(['payment_status' => 'paid', 'status' => 'in_production']);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('admin.encomendas.link-pagamento', $order))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta encomenda já está paga.');
    }

    public function test_the_button_is_only_for_the_backoffice(): void
    {
        $order = $this->order();

        $this->postJson(route('admin.encomendas.link-pagamento', $order))->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.encomendas.link-pagamento', $order))
            ->assertForbidden();

        $this->assertSame([], $this->stripe['sessions']);
    }

    public function test_the_pay_page_also_charges_the_discount(): void
    {
        $order = $this->order(['discount_cents' => 500, 'promo_code' => 'OLA5', 'total_cents' => 2500]);

        $this->post(route('payment-link.pay', $order->guest_access_token));

        $session = array_values($this->stripe['sessions'])[0];

        $this->assertSame(2500, $session['amount_total']);
    }
}
