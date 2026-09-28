<?php

namespace Tests\Feature\Mcp;

use App\Mail\OrderConfirmationMail;
use App\Mail\OrderShippedMail;
use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Write\Orders\OrderCreateManualTool;
use App\Mcp\Tools\Write\Orders\OrderItemSetProductionStatusTool;
use App\Mcp\Tools\Write\Orders\OrderSetPaymentStatusTool;
use App\Mcp\Tools\Write\Orders\OrderTransitionTool;
use App\Models\McpActivity;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OrderWriteToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->admin = User::factory()->admin()->create();
    }

    private function writer(): PendingTestResponse
    {
        Passport::actingAs($this->admin, ['mcp:read', 'mcp:write']);

        return StudioServer::actingAs($this->admin);
    }

    private function stockVariant(int $stock = 5, int $priceCents = 1990): Variant
    {
        $product = Product::factory()->create(['fulfillment_mode' => 'in_stock']);

        return Variant::factory()->create([
            'product_id' => $product->id,
            'stock' => $stock,
            'price_cents' => $priceCents,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function order(Variant $variant, array $overrides = []): array
    {
        return [
            'customer_name' => 'Rita Sousa',
            'email' => 'rita.sousa@example.test',
            'phone' => '912345678',
            'sales_channel' => 'vinted',
            'payment_method' => 'vinted',
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            ...$overrides,
        ];
    }

    public function test_orders_need_a_write_key(): void
    {
        Passport::actingAs($this->admin, ['mcp:read']);

        StudioServer::actingAs($this->admin)
            ->tool(OrderCreateManualTool::class, $this->order($this->stockVariant()))
            ->assertNotRegistered();
    }

    public function test_a_manual_order_uses_the_catalog_price_and_takes_stock(): void
    {
        $variant = $this->stockVariant(stock: 5, priceCents: 1990);

        $this->writer()->tool(OrderCreateManualTool::class, $this->order($variant))
            ->assertOk()
            ->assertSee('registada')
            // Resposta com contactos mascarados, nunca em claro.
            ->assertSee('r***@example.test')
            ->assertDontSee('rita.sousa@')
            ->assertDontSee('912345678');

        $order = Order::query()->sole();
        $this->assertSame(3980, $order->subtotal_cents);
        $this->assertSame('vinted', $order->sales_channel);
        $this->assertSame($this->admin->id, $order->created_by_user_id);
        $this->assertSame(3, $variant->refresh()->stock);

        // Sem pedir, nao ha email ao cliente.
        Mail::assertNotQueued(OrderConfirmationMail::class);
    }

    public function test_the_confirmation_email_is_opt_in(): void
    {
        $this->writer()->tool(OrderCreateManualTool::class, $this->order($this->stockVariant(), ['send_confirmation' => true]))
            ->assertOk()
            ->assertSee('Email de confirmação enviado');

        Mail::assertQueued(OrderConfirmationMail::class);
    }

    public function test_a_different_price_needs_a_reason(): void
    {
        $variant = $this->stockVariant();

        $this->writer()->tool(OrderCreateManualTool::class, $this->order($variant, [
            'items' => [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => '10']],
        ]))->assertHasErrors()->assertSee('motivo');

        $this->assertSame(0, Order::query()->count());

        $this->writer()->tool(OrderCreateManualTool::class, $this->order($variant, [
            'items' => [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => '10', 'price_override_reason' => 'Desconto Vinted']],
        ]))->assertOk();

        $this->assertSame(1000, Order::query()->sole()->subtotal_cents);
    }

    public function test_no_stock_means_no_order(): void
    {
        $variant = $this->stockVariant(stock: 1);

        $this->writer()->tool(OrderCreateManualTool::class, $this->order($variant))
            ->assertHasErrors()
            ->assertSee('Sem stock suficiente');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, $variant->refresh()->stock);
    }

    public function test_the_email_is_required_outside_hand_sales(): void
    {
        $this->writer()->tool(OrderCreateManualTool::class, $this->order($this->stockVariant(), ['email' => null]))
            ->assertHasErrors();
    }

    public function test_paying_moves_the_order_forward(): void
    {
        $variant = $this->stockVariant();
        $this->writer()->tool(OrderCreateManualTool::class, $this->order($variant))->assertOk();
        $order = Order::query()->sole();

        $this->writer()->tool(OrderSetPaymentStatusTool::class, ['order_number' => $order->order_number, 'payment_status' => 'paid'])
            ->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        // So artigos em stock: nao ha nada para produzir, segue para expedicao.
        $this->assertSame('ready_to_ship', $order->status);
    }

    public function test_refunds_and_failures_stay_in_the_backoffice(): void
    {
        $order = Order::factory()->paid()->create();

        $this->writer()->tool(OrderSetPaymentStatusTool::class, ['order_id' => $order->id, 'payment_status' => 'refunded'])
            ->assertHasErrors();

        $this->writer()->tool(OrderTransitionTool::class, ['order_id' => $order->id, 'status' => 'cancelled'])
            ->assertHasErrors();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('paid', $order->status);
    }

    public function test_an_unpaid_order_only_advances_with_force_and_a_note(): void
    {
        $order = Order::factory()->create(['status' => 'pending_payment', 'payment_status' => 'pending']);

        $this->writer()->tool(OrderTransitionTool::class, ['order_id' => $order->id, 'status' => 'in_production'])
            ->assertHasErrors();

        $this->writer()->tool(OrderTransitionTool::class, ['order_id' => $order->id, 'status' => 'in_production', 'force' => true])
            ->assertHasErrors()
            ->assertSee('nota');

        $this->writer()->tool(OrderTransitionTool::class, [
            'order_id' => $order->id,
            'status' => 'in_production',
            'force' => true,
            'note' => 'Paga em mão amanhã',
        ])->assertOk();

        $this->assertSame('in_production', $order->refresh()->status);
    }

    public function test_shipping_sends_the_shipped_email(): void
    {
        $order = Order::factory()->paid()->create(['status' => 'ready_to_ship', 'email' => 'cliente@example.test']);

        $this->writer()->tool(OrderTransitionTool::class, ['order_id' => $order->id, 'status' => 'shipped'])
            ->assertOk()
            ->assertSee('Email de expedição');

        Mail::assertQueued(OrderShippedMail::class);
        $this->assertNotNull($order->refresh()->shipped_at);
    }

    public function test_orders_never_go_backwards(): void
    {
        $order = Order::factory()->paid()->create(['status' => 'shipped']);

        $this->writer()->tool(OrderTransitionTool::class, ['order_id' => $order->id, 'status' => 'in_production'])
            ->assertHasErrors();

        $this->assertSame('shipped', $order->refresh()->status);
    }

    public function test_the_last_ready_item_moves_the_order_to_shipping(): void
    {
        $order = Order::factory()->paid()->create(['status' => 'in_production']);
        $item = OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id, 'production_status' => 'quality_check']);

        $this->writer()->tool(OrderItemSetProductionStatusTool::class, ['item_id' => $item->id, 'production_status' => 'ready'])
            ->assertOk();

        $this->assertSame('ready', $item->refresh()->production_status);
        $this->assertSame('ready_to_ship', $order->refresh()->status);

        $activity = McpActivity::query()->where('tool', 'order_item_set_production_status')->sole();
        $this->assertSame('quality_check', $activity->changes['before']['production_status']);
    }

    public function test_the_audit_masks_the_customer_contacts(): void
    {
        $this->writer()->tool(OrderCreateManualTool::class, $this->order($this->stockVariant()))->assertOk();

        $activity = McpActivity::query()->where('tool', 'order_create_manual')->sole();

        $this->assertSame('r***@example.test', $activity->arguments['email']);
        $this->assertSame('9******78', $activity->arguments['phone']);
    }
}
