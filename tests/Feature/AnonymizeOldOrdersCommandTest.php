<?php

namespace Tests\Feature;

use App\Console\Commands\AnonymizeOldOrdersCommand;
use App\Models\EtsyReceipt;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemStatusHistory;
use App\Models\OrderStatusHistory;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnonymizeOldOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Uma encomenda com dados pessoais em todo o lado onde eles moram,
     * mexida pela ultima vez em $touched.
     */
    private function orderTouchedAt(string $touched): Order
    {
        $this->travelTo(Carbon::parse($touched));

        $order = Order::factory()->create([
            'customer_name' => 'Ana Silva',
            'email' => 'ana@example.test',
            'phone' => '912345678',
            'nif' => '123456789',
            'shipping_address' => ['line1' => 'Rua das Flores 12', 'postalCode' => '4000-000', 'city' => 'Porto', 'country' => 'PT'],
            'billing_address' => ['line1' => 'Rua das Flores 12'],
            'tracking_number' => 'RR123456789PT',
            'tracking_url' => 'https://ctt.pt/RR123456789PT',
            'admin_note' => 'A Ana pediu embrulho.',
            'total_cents' => 4890,
        ]);

        $item = OrderItem::factory()->for($order)->create([
            'product_name' => 'Vaso Geo',
            'personalization' => ['texto' => 'Ana'],
        ]);

        OrderStatusHistory::query()->create(['order_id' => $order->id, 'to_status' => 'shipped', 'note' => 'Ligou a Ana.']);
        OrderItemStatusHistory::query()->create(['order_item_id' => $item->id, 'to_status' => 'printing', 'note' => 'Nome da Ana.']);

        PromoCodeRedemption::query()->create([
            'promo_code_id' => PromoCode::factory()->create()->id,
            'order_id' => $order->id,
            'email' => 'ana@example.test',
            'unique_email' => 'ana@example.test',
            'discount_cents' => 500,
        ]);

        EtsyReceipt::query()->create([
            'etsy_receipt_id' => random_int(1, 1_000_000),
            'order_id' => $order->id,
            'status' => 'imported',
            'buyer_message' => 'Sou a Ana.',
            'gift_message' => 'Para a Ana.',
        ]);

        $this->travelBack();

        return $order;
    }

    public function test_the_personal_data_stays_through_the_tenth_calendar_year(): void
    {
        $order = $this->orderTouchedAt('2026-05-01 10:00');

        $this->travelTo(Carbon::parse('2036-12-31 23:00'));
        $this->artisan('orders:anonymize')->assertExitCode(0);

        $this->assertSame('Ana Silva', $order->fresh()?->customer_name);
    }

    public function test_after_ten_calendar_years_the_customer_leaves_and_the_sale_stays(): void
    {
        $order = $this->orderTouchedAt('2026-05-01 10:00');
        $recent = $this->orderTouchedAt('2027-03-01 10:00');

        $this->travelTo(Carbon::parse('2037-01-01 04:55'));
        $this->artisan('orders:anonymize')
            ->expectsOutputToContain('1 encomenda(s) anteriores a 2027')
            ->assertExitCode(0);

        $fresh = $order->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(AnonymizeOldOrdersCommand::CUSTOMER_NAME, $fresh->customer_name);
        foreach (['email', 'phone', 'nif', 'shipping_address', 'billing_address', 'tracking_number', 'tracking_url', 'admin_note'] as $field) {
            $this->assertNull($fresh->{$field}, "{$field} devia ter saído");
        }

        // A venda fica: numero, total, pecas.
        $this->assertSame($order->order_number, $fresh->order_number);
        $this->assertSame(4890, $fresh->total_cents);
        $item = $fresh->items()->sole();
        $this->assertSame('Vaso Geo', $item->product_name);
        $this->assertNull($item->personalization);

        $this->assertNull(OrderStatusHistory::query()->where('order_id', $order->id)->sole()->note);
        $this->assertNull(OrderItemStatusHistory::query()->where('order_item_id', $item->id)->sole()->note);

        $redemption = PromoCodeRedemption::query()->where('order_id', $order->id)->sole();
        $this->assertNull($redemption->email);
        $this->assertNull($redemption->unique_email);
        $this->assertSame(500, $redemption->discount_cents);

        $receipt = EtsyReceipt::query()->where('order_id', $order->id)->sole();
        $this->assertNull($receipt->buyer_message);
        $this->assertNull($receipt->gift_message);

        // A de 2027 ainda esta dentro do prazo, com tudo o que tinha.
        $this->assertSame('Ana Silva', $recent->fresh()?->customer_name);
        $this->assertSame('Sou a Ana.', EtsyReceipt::query()->where('order_id', $recent->id)->sole()->buyer_message);
        $this->assertSame('ana@example.test', PromoCodeRedemption::query()->where('order_id', $recent->id)->sole()->email);
    }

    public function test_an_order_is_only_anonymized_once(): void
    {
        $this->orderTouchedAt('2026-05-01 10:00');

        $this->travelTo(Carbon::parse('2037-01-01 04:55'));
        $this->artisan('orders:anonymize')->expectsOutputToContain('1 encomenda(s)');

        $this->travelTo(Carbon::parse('2037-01-02 04:55'));
        $this->artisan('orders:anonymize')->expectsOutputToContain('0 encomenda(s)');
    }

    public function test_it_runs_every_night(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'orders:anonymize'));

        $this->assertNotNull($event);
        $this->assertSame('55 4 * * *', $event->expression);
    }
}
