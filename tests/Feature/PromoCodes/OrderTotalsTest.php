<?php

namespace Tests\Feature\PromoCodes;

use App\Models\Order;
use App\Models\OrderDraft;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\OrderService;
use App\Services\OrderTotalsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderTotalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function manualOrder(int $unitCents = 3000, array $overrides = []): Order
    {
        return app(OrderService::class)->createManual([
            'customer_name' => 'Ana',
            'email' => 'ana@example.com',
            'sales_channel' => 'instagram',
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'shipping_price' => '0',
            'items' => [[
                'product_name' => 'Vaso',
                'variant_label' => '',
                'sku' => '',
                'unit_price' => number_format($unitCents / 100, 2, '.', ''),
                'qty' => 1,
                'fulfillment_mode' => 'made_to_order',
            ]],
            ...$overrides,
        ], User::factory()->admin()->create());
    }

    public function test_shipping_is_paid_at_24_99_and_free_at_25_00(): void
    {
        $calculator = app(OrderTotalsCalculator::class);

        $this->assertSame(450, $calculator->calculate(2499)->shippingCents);
        $this->assertSame(2949, $calculator->calculate(2499)->totalCents);
        $this->assertSame(0, $calculator->calculate(2500)->shippingCents);
        $this->assertSame(2500, $calculator->calculate(2500)->totalCents);
    }

    public function test_shipping_stays_free_when_the_discount_takes_the_total_below_25(): void
    {
        $totals = app(OrderTotalsCalculator::class)->calculate(2500, 500);

        $this->assertSame(0, $totals->shippingCents);
        $this->assertSame(2000, $totals->totalCents);
    }

    public function test_the_shipping_limits_come_from_the_config(): void
    {
        config(['shop.shipping.national_cents' => 300, 'shop.shipping.free_above_cents' => 1000]);
        $calculator = app(OrderTotalsCalculator::class);

        $this->assertSame(300, $calculator->suggestedShipping(999));
        $this->assertSame(0, $calculator->suggestedShipping(1000));
    }

    public function test_a_manual_order_keeps_the_code_as_a_snapshot(): void
    {
        $promoCode = PromoCode::factory()->create(['code' => 'OLA5', 'value' => 500]);

        $order = $this->manualOrder(3000, ['promo_code' => 'ola5', 'shipping_price' => '4.50']);

        $this->assertSame($promoCode->id, $order->promo_code_id);
        $this->assertSame('OLA5', $order->promo_code);
        $this->assertSame(500, $order->discount_cents);
        $this->assertSame(450, $order->shipping_cents);
        $this->assertSame(2950, $order->total_cents);
        $this->assertSame(0, $promoCode->fresh()->uses_count);
    }

    public function test_a_refused_code_stops_the_order_with_a_field_error(): void
    {
        PromoCode::factory()->create(['code' => 'MIN20', 'min_subtotal_cents' => 2000]);

        try {
            $this->manualOrder(1000, ['promo_code' => 'MIN20']);
            $this->fail('A encomenda devia ter sido recusada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('promo_code', $exception->errors());
        }

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_manual_order_without_a_code_works_as_before(): void
    {
        $order = $this->manualOrder(3000, ['shipping_price' => '4.50']);

        $this->assertNull($order->promo_code_id);
        $this->assertSame(0, $order->discount_cents);
        $this->assertSame(3450, $order->total_cents);
    }

    public function test_an_adjustment_keeps_the_discount_in_the_total(): void
    {
        PromoCode::factory()->create(['code' => 'OLA5', 'value' => 500]);
        $order = $this->manualOrder(3000, ['promo_code' => 'OLA5']);

        $order = app(OrderService::class)->setAdjustment($order, -200, 'Combinado');

        $this->assertSame(2300, $order->total_cents);
    }

    public function test_an_old_order_keeps_its_total_when_adjusted_to_zero(): void
    {
        $order = Order::factory()->create(['subtotal_cents' => 1000, 'shipping_cents' => 450, 'total_cents' => 1450]);

        $this->assertSame(0, $order->fresh()->discount_cents);

        $order = app(OrderService::class)->setAdjustment($order->fresh(), 100, 'Extra');

        $this->assertSame(1550, $order->total_cents);
    }

    public function test_the_preview_uses_the_same_service(): void
    {
        PromoCode::factory()->create(['code' => 'OLA5', 'value' => 500]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('admin.encomendas.totais'), [
                'email' => 'ana@example.com',
                'promo_code' => 'OLA5',
                'shipping_price' => '0',
                'items' => [['unit_price' => '12.50', 'qty' => 2]],
            ])
            ->assertOk()
            ->assertJson([
                'subtotalCents' => 2500,
                'discountCents' => 500,
                'shippingCents' => 0,
                'totalCents' => 2000,
                'suggestedShippingCents' => 0,
                'promoError' => null,
            ]);
    }

    public function test_the_preview_is_only_for_the_backoffice(): void
    {
        $this->postJson(route('admin.encomendas.totais'), [])->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.encomendas.totais'), [])
            ->assertForbidden();
    }

    public function test_the_draft_list_total_takes_the_code_into_account(): void
    {
        PromoCode::factory()->create(['code' => 'OLA5', 'value' => 500]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.encomendas.rascunhos.store'), [
                'customer_name' => 'Ana',
                'promo_code' => 'OLA5',
                'shipping_price' => '0',
                'send_confirmation' => false,
                'items' => [['unit_price' => '30.00', 'qty' => 1]],
            ]);

        $this->assertSame(2500, OrderDraft::query()->sole()->total_cents);
        $this->assertSame('OLA5', OrderDraft::query()->sole()->payload['promo_code']);
    }
}
