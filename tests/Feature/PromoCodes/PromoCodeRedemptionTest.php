<?php

namespace Tests\Feature\PromoCodes;

use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PromoCodeRedemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function orderWith(PromoCode $promoCode, string $email = 'ana@example.com'): Order
    {
        return Order::factory()->create([
            'email' => $email,
            'promo_code_id' => $promoCode->id,
            'promo_code' => $promoCode->code,
            'discount_cents' => 500,
            'subtotal_cents' => 3000,
            'shipping_cents' => 0,
            'total_cents' => 2500,
            'status' => 'pending_payment',
            'payment_status' => 'pending',
        ])->fresh();
    }

    private function pay(Order $order): Order
    {
        return app(OrderService::class)->setPaymentStatus($order, 'paid');
    }

    public function test_the_use_is_only_recorded_when_the_order_is_paid(): void
    {
        $promoCode = PromoCode::factory()->create();
        $order = $this->orderWith($promoCode);

        $this->assertSame(0, $promoCode->fresh()->uses_count);
        $this->assertSame(0, PromoCodeRedemption::query()->count());

        $this->pay($order);

        $this->assertSame(1, $promoCode->fresh()->uses_count);
        $this->assertDatabaseHas('promo_code_redemptions', [
            'promo_code_id' => $promoCode->id,
            'order_id' => $order->id,
            'email' => 'ana@example.com',
            'discount_cents' => 500,
        ]);
    }

    public function test_two_payments_on_the_last_use_only_record_one(): void
    {
        $promoCode = PromoCode::factory()->create(['max_uses' => 1]);
        $first = $this->orderWith($promoCode, 'ana@example.com');
        $second = $this->orderWith($promoCode, 'rui@example.com');

        $this->pay($first);
        $this->pay($second);

        $this->assertSame(1, $promoCode->fresh()->uses_count);
        $this->assertSame(1, PromoCodeRedemption::query()->count());
        $this->assertDatabaseHas('promo_code_redemptions', ['order_id' => $first->id]);

        // O segundo pagou com desconto: fica pago, e o dono fica a saber.
        $this->assertTrue($second->fresh()->isPaid());
        $this->assertTrue($second->statusHistories()->where('note', 'like', '%não ficou registado%')->exists());
    }

    public function test_the_same_email_on_a_single_use_code_only_records_one(): void
    {
        $promoCode = PromoCode::factory()->create(['single_use_per_email' => true]);
        $first = $this->orderWith($promoCode, 'ana@example.com');
        $second = $this->orderWith($promoCode, 'ANA@example.com');

        $this->pay($first);
        $this->pay($second);

        $this->assertSame(1, $promoCode->fresh()->uses_count);
        $this->assertSame(1, PromoCodeRedemption::query()->count());
        $this->assertTrue($second->fresh()->isPaid());
    }

    public function test_different_emails_can_use_a_code_that_is_not_personal(): void
    {
        $promoCode = PromoCode::factory()->create(['single_use_per_email' => true]);

        $this->pay($this->orderWith($promoCode, 'ana@example.com'));
        $this->pay($this->orderWith($promoCode, 'rui@example.com'));

        $this->assertSame(2, $promoCode->fresh()->uses_count);
    }

    public function test_an_order_paid_again_does_not_record_a_second_use(): void
    {
        $promoCode = PromoCode::factory()->create();
        $order = $this->orderWith($promoCode);

        $order = $this->pay($order);
        $order->update(['payment_status' => 'partially_refunded']);
        $this->pay($order->fresh());

        $this->assertSame(1, $promoCode->fresh()->uses_count);
    }

    public function test_a_refund_does_not_give_the_use_back(): void
    {
        $promoCode = PromoCode::factory()->create();
        $order = $this->pay($this->orderWith($promoCode));

        app(OrderService::class)->setPaymentStatus($order, 'refunded');

        $this->assertSame(1, $promoCode->fresh()->uses_count);
    }

    public function test_a_manual_order_marked_as_paid_also_records_the_use(): void
    {
        $promoCode = PromoCode::factory()->create(['code' => 'MAO']);

        app(OrderService::class)->createManual([
            'customer_name' => 'Ana',
            'email' => null,
            'sales_channel' => 'manual',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'shipping_price' => '0',
            'promo_code' => 'MAO',
            'items' => [[
                'product_name' => 'Vaso',
                'variant_label' => '',
                'sku' => '',
                'unit_price' => '30.00',
                'qty' => 1,
                'fulfillment_mode' => 'made_to_order',
            ]],
        ], User::factory()->admin()->create());

        $this->assertSame(1, $promoCode->fresh()->uses_count);
    }
}
