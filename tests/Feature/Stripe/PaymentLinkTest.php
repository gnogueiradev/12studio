<?php

namespace Tests\Feature\Stripe;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Variant;
use App\Stripe\StripeCatalog;
use App\Support\OrderPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * O link /pagar/{token} que o dono manda ao cliente. Abrir so mostra; pagar
 * cria a sessao do Stripe com o que a encomenda diz — e so isso.
 */
class PaymentLinkTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
    }

    /**
     * Um vaso grande ao preco normal, dois pequenos com desconto na linha,
     * portes de 4 €.
     */
    private function order(int $adjustment = 0): Order
    {
        config(['stripe.enabled' => false]);
        $product = Product::factory()->create(['name' => 'Vaso']);
        $large = Variant::factory()->for($product)->create(['size_label' => 'G', 'price_cents' => 2500]);
        $small = Variant::factory()->for($product)->create(['size_label' => 'P', 'price_cents' => 1500]);
        config(['stripe.enabled' => true]);

        app(StripeCatalog::class)->sync($product);

        $order = Order::factory()->create([
            'subtotal_cents' => 5300,
            'shipping_cents' => 400,
            'shipping_method_name' => 'CTT',
            'adjustment_cents' => $adjustment,
            'adjustment_reason' => $adjustment === 0 ? null : 'Combinado',
            'total_cents' => 5700 + $adjustment,
            'email' => 'cliente@example.com',
            'phone' => '912345678',
            'shipping_address' => ['line1' => 'Rua Secreta 1', 'city' => 'Porto'],
        ]);

        OrderItem::factory()->for($order)->create([
            'variant_id' => $large->id, 'product_name' => 'Vaso', 'variant_label' => 'G',
            'unit_price_cents' => 2500, 'qty' => 1, 'line_total_cents' => 2500,
        ]);
        OrderItem::factory()->for($order)->create([
            'variant_id' => $small->id, 'product_name' => 'Vaso', 'variant_label' => 'P',
            'unit_price_cents' => 1400, 'qty' => 2, 'line_total_cents' => 2800,
        ]);

        return $order->refresh();
    }

    private function link(Order $order): string
    {
        return '/pagar/'.$order->guest_access_token;
    }

    public function test_opening_the_link_shows_the_summary_without_personal_data_nor_calling_stripe(): void
    {
        $order = $this->order();
        $calls = count($this->stripeCalls);

        $response = $this->get($this->link($order));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('pagar')
            ->where('order.number', $order->order_number)
            ->where('order.totalCents', 5700)
            ->has('order.items', 2)
            ->where('state', 'payable'));

        $this->assertSame($calls, count($this->stripeCalls));
        $this->assertStringNotContainsString('Rua Secreta', $response->getContent());
        $this->assertStringNotContainsString('cliente@example.com', $response->getContent());
        $this->assertStringNotContainsString('912345678', $response->getContent());
    }

    public function test_a_wrong_token_or_stripe_disabled_is_a_404(): void
    {
        $order = $this->order();

        $this->get('/pagar/'.str_repeat('a', 64))->assertNotFound();

        config(['stripe.enabled' => false]);
        $this->get($this->link($order))->assertNotFound();
        $this->post($this->link($order))->assertNotFound();
    }

    public function test_paying_creates_a_session_with_what_the_order_says(): void
    {
        $order = $this->order();

        $response = $this->post($this->link($order));

        $order->refresh();
        $session = $this->stripe['sessions'][$order->stripe_session_id];
        $response->assertRedirect($session['url']);

        $request = $session['request'];
        $large = Variant::query()->where('size_label', 'G')->first();

        // Preco normal: o Price da variante.
        $this->assertSame($large->stripe_price_id, $request['line_items'][0]['price']);
        // Preco mudado na encomenda: um preco so dela, no mesmo Product.
        $this->assertSame('1400', $request['line_items'][1]['price_data']['unit_amount']);
        $this->assertSame($large->product->stripe_product_id, $request['line_items'][1]['price_data']['product']);
        $this->assertSame('400', $request['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount']);
        $this->assertSame((string) $order->id, $request['client_reference_id']);
        $this->assertSame((string) $order->id, $request['metadata']['order_id']);
        $this->assertSame((string) $order->id, $request['payment_intent_data']['metadata']['order_id']);
        $this->assertStringContainsString('1× Vaso (G)', $request['custom_text']['submit']['message']);
        $this->assertSame(5700, $session['amount_total']);
    }

    public function test_a_discount_is_a_single_use_coupon(): void
    {
        $order = $this->order(-500);

        $this->post($this->link($order));

        $session = $this->stripe['sessions'][$order->refresh()->stripe_session_id];
        $coupon = $this->stripe['coupons'][$session['request']['discounts'][0]['coupon']];

        $this->assertSame('500', $coupon['amount_off']);
        $this->assertSame('1', $coupon['max_redemptions']);
        $this->assertSame(5200, $session['amount_total']);
    }

    public function test_an_extra_is_its_own_line(): void
    {
        $order = $this->order(300);

        $this->post($this->link($order));

        $session = $this->stripe['sessions'][$order->refresh()->stripe_session_id];

        $this->assertSame('Combinado', $session['request']['line_items'][2]['price_data']['product_data']['name']);
        $this->assertSame(6000, $session['amount_total']);
    }

    public function test_paying_again_expires_the_previous_session(): void
    {
        $order = $this->order();

        $this->post($this->link($order));
        $first = $order->refresh()->stripe_session_id;

        $this->post($this->link($order));

        $this->assertSame('expired', $this->stripe['sessions'][$first]['status']);
        $this->assertNotSame($first, $order->refresh()->stripe_session_id);
    }

    public function test_a_submitted_session_is_not_replaced(): void
    {
        $order = $this->order();
        $this->post($this->link($order));
        $first = $order->refresh()->stripe_session_id;
        $this->stripe['sessions'][$first]['status'] = 'complete';

        $this->post($this->link($order))->assertRedirect($this->link($order));

        $this->assertSame($first, $order->refresh()->stripe_session_id);
        $this->assertCount(1, $this->stripeCallsTo('POST', 'checkout/sessions'));
    }

    public function test_an_order_that_does_not_add_up_is_not_charged(): void
    {
        $order = $this->order();
        $order->forceFill(['total_cents' => 9999])->save();

        $this->post($this->link($order))->assertSessionHasErrors('payment');

        $this->assertCount(0, $this->stripeCallsTo('POST', 'checkout/sessions'));
        $this->assertCount(0, $this->stripeCallsTo('POST', 'coupons'));
    }

    public function test_a_line_whose_product_is_not_in_stripe_goes_by_name(): void
    {
        $order = $this->order();
        OrderItem::factory()->for($order)->create([
            'variant_id' => null, 'product_name' => 'Peça à medida', 'variant_label' => null,
            'unit_price_cents' => 1000, 'qty' => 1, 'line_total_cents' => 1000,
        ]);
        $order->forceFill(['total_cents' => 6700])->save();

        $this->post($this->link($order));

        $request = $this->stripe['sessions'][$order->refresh()->stripe_session_id]['request'];
        $this->assertSame('Peça à medida', $request['line_items'][2]['price_data']['product_data']['name']);
    }

    public function test_a_paid_or_cancelled_order_shows_its_state_and_cannot_be_paid(): void
    {
        $paid = $this->order();
        $paid->forceFill(['payment_status' => 'paid', 'status' => 'paid'])->save();

        $this->get($this->link($paid))->assertInertia(fn (Assert $page) => $page->where('state', 'paid'));
        $this->post($this->link($paid))->assertRedirect($this->link($paid));

        $cancelled = Order::factory()->create(['status' => 'cancelled']);
        $this->get($this->link($cancelled))->assertInertia(fn (Assert $page) => $page->where('state', 'unavailable'));

        $this->assertCount(0, $this->stripeCallsTo('POST', 'checkout/sessions'));
    }

    public function test_the_admin_sees_the_link_only_while_it_can_be_paid(): void
    {
        $order = $this->order();

        $this->assertSame(url($this->link($order)), OrderPresenter::detail($order)['paymentLink']);

        $order->forceFill(['payment_status' => 'paid'])->save();
        $this->assertNull(OrderPresenter::detail($order)['paymentLink']);

        config(['stripe.enabled' => false]);
        $order->forceFill(['payment_status' => 'pending'])->save();
        $this->assertNull(OrderPresenter::detail($order)['paymentLink']);
    }

    public function test_a_discount_larger_than_the_lines_is_refused(): void
    {
        // Linhas 53 €, portes 4 €: um desconto de 55 € so cabia tirando aos
        // portes, e o cupao do Stripe nao lhes toca.
        $order = $this->order(-5500);

        $this->post($this->link($order))->assertSessionHasErrors('payment');

        $this->assertCount(0, $this->stripeCallsTo('POST', 'coupons'));
    }

    public function test_a_payment_already_being_opened_is_not_opened_twice(): void
    {
        $order = $this->order();
        $lock = Cache::lock("stripe-checkout:{$order->id}", 30);
        $lock->get();

        $this->post($this->link($order))->assertSessionHasErrors('payment');

        $lock->release();
        $this->assertCount(0, $this->stripeCallsTo('POST', 'checkout/sessions'));
    }

    public function test_a_refunded_order_is_not_shown_as_paid(): void
    {
        $order = $this->order();
        $order->forceFill(['payment_status' => 'refunded', 'status' => 'refunded'])->save();

        $this->get($this->link($order))->assertInertia(fn (Assert $page) => $page->where('state', 'unavailable'));
    }
}
