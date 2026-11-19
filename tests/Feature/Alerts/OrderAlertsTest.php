<?php

namespace Tests\Feature\Alerts;

use App\Alerts\AlertChannel;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderAlertsTest extends TestCase
{
    use FakesDiscord;
    use RefreshDatabase;

    private OrderService $orders;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeDiscord();

        $this->orders = app(OrderService::class);
        $this->admin = User::factory()->admin()->create(['name' => 'Gonçalo']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function manualOrder(array $overrides = []): Order
    {
        return $this->orders->createManual(array_merge([
            'customer_name' => 'Ana Silva',
            'email' => 'ana.silva@example.test',
            'phone' => '912345678',
            'sales_channel' => 'instagram',
            'payment_method' => 'mbway',
            'payment_status' => 'pending',
            'shipping_price' => '3.50',
            'line1' => 'Rua das Flores 12',
            'line2' => '',
            'postal_code' => '4000-000',
            'city' => 'Porto',
            'country' => 'PT',
            'items' => [[
                'variant_id' => null,
                'product_name' => 'Vaso Geo',
                'variant_label' => 'M',
                'sku' => '',
                'unit_price' => '22.70',
                'qty' => 2,
                'fulfillment_mode' => 'made_to_order',
                'vat_rate' => 23,
            ]],
        ], $overrides), $this->admin);
    }

    public function test_a_new_order_carries_the_pieces_and_the_link_but_no_customer_data(): void
    {
        $order = $this->manualOrder(['nif' => '123456789']);

        $embed = $this->alertEmbed(AlertChannel::ORDERS, "Nova encomenda {$order->order_number}");
        $text = $this->embedText($embed);

        foreach (['2× Vaso Geo (M)', '48,90 €', 'Instagram', 'Gonçalo'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }

        $this->assertNoCustomerData($text);
        $this->assertSame(route('admin.encomendas.show', $order), $embed['url']);
    }

    /**
     * O Discord e um terceiro fora da UE: dos clientes nao sai nada.
     */
    private function assertNoCustomerData(string $text): void
    {
        foreach (['Ana Silva', 'ana.silva@example.test', '912345678', '123456789', 'Rua das Flores', '4000-000', 'RR123456789PT'] as $personal) {
            $this->assertStringNotContainsString($personal, $text);
        }
    }

    public function test_an_order_registered_as_paid_reads_in_order(): void
    {
        $order = $this->manualOrder(['payment_status' => 'paid']);

        $this->assertSame([
            "🛒 Nova encomenda {$order->order_number}",
            "💶 Pagamento registado — {$order->order_number}",
        ], $this->alertTitles(AlertChannel::ORDERS));
    }

    public function test_a_marketplace_sale_without_stock_reads_as_paid(): void
    {
        $variant = Variant::factory()->stock(0)->create();

        $order = $this->orders->createFromMarketplace([
            'sales_channel' => 'etsy',
            'payment_method' => 'etsy',
            'external_order_reference' => 'ETSY-1',
            'customer_name' => 'Ana Silva',
            'shipping_address' => null,
            'shipping_cents' => 0,
            'adjustment_cents' => 0,
            'adjustment_reason' => null,
            'stock_reason' => 'etsy_order',
            'note' => 'Importada da Etsy.',
            'lines' => [['variant' => $variant, 'product_name' => 'Vaso', 'sku' => null, 'qty' => 1, 'unit_price_cents' => 1500, 'personalization' => null]],
        ]);

        $embed = $this->alertEmbed(AlertChannel::ORDERS, "Vendida sem stock — {$order->order_number}");
        $this->assertSame(
            'A venda já aconteceu e está paga, mas o stock não chega para tudo. A encomenda entrou marcada; da prateleira saiu só o que havia.',
            $embed['description'],
        );
        $this->assertSame([
            "🛒 Nova encomenda {$order->order_number}",
            "⚠️ Vendida sem stock — {$order->order_number}",
            "💶 Pagamento registado — {$order->order_number}",
        ], array_values(array_filter(
            $this->alertTitles(AlertChannel::ORDERS),
            fn (string $title): bool => preg_match('/Nova encomenda|Vendida sem stock|Pagamento registado/', $title) === 1,
        )));
    }

    public function test_a_failed_payment_arrives_once_as_a_cancellation_with_the_reason(): void
    {
        $order = $this->manualOrder();

        $this->orders->setPaymentStatus($order, 'failed', $this->admin);

        $embed = $this->alertEmbed(AlertChannel::ORDERS, "Cancelada — {$order->order_number}");
        $this->assertStringContainsString('Pagamento falhou', $this->embedText($embed));
        $this->assertNotAlerted(AlertChannel::ORDERS, 'Pagamento de');
    }

    public function test_a_forced_advance_is_flagged_with_the_note(): void
    {
        $order = $this->manualOrder();

        $this->orders->transitionOrder($order, 'in_production', $this->admin, 'Paga na entrega.', true);

        $embed = $this->alertEmbed(AlertChannel::ORDERS, 'Avançada sem pagamento');
        $this->assertStringContainsString('Paga na entrega.', $this->embedText($embed));
    }

    public function test_shipping_says_whether_the_customer_was_emailed(): void
    {
        $order = $this->manualOrder(['payment_status' => 'paid']);
        $order->items()->update(['production_status' => 'ready']);
        $this->orders->transitionOrder($order->refresh(), 'ready_to_ship', $this->admin);
        $this->orders->setTracking($order->refresh(), ['tracking_number' => 'RR123456789PT', 'shipping_method_name' => 'CTT']);

        $this->orders->transitionOrder($order->refresh(), 'shipped', $this->admin);

        $ready = $this->embedText($this->alertEmbed(AlertChannel::STOCK, "Pronta a enviar — {$order->order_number}"));
        $this->assertStringContainsString('2× Vaso Geo (M)', $ready);
        $this->assertNoCustomerData($ready);

        $shipped = $this->embedText($this->alertEmbed(AlertChannel::ORDERS, "Enviada — {$order->order_number}"));
        $this->assertStringContainsString('Enviado ao cliente', $shipped);
        $this->assertStringContainsString('CTT', $shipped);
        $this->assertNoCustomerData($shipped);
    }

    public function test_the_production_board_reports_each_move_and_the_ready_order(): void
    {
        $order = $this->manualOrder(['payment_status' => 'paid']);
        $item = $order->items()->sole();

        $this->orders->setItemProductionStatus($item, 'printing', $this->admin);
        $this->orders->setItemProductionStatus($item->refresh(), 'ready', $this->admin);

        $this->assertSame([
            '🖨️ Vaso Geo (M) — A imprimir',
            '✅ Vaso Geo (M) — Pronto',
            "📦 Pronta a enviar — {$order->order_number}",
        ], $this->alertTitles(AlertChannel::STOCK));
    }

    public function test_a_piece_going_back_is_a_warning(): void
    {
        $order = $this->manualOrder(['payment_status' => 'paid']);
        $item = $order->items()->sole();
        $this->orders->setItemProductionStatus($item, 'quality_check', $this->admin);

        $this->orders->setItemProductionStatus($item->refresh(), 'printing', $this->admin, 'Camada descolou.');

        $embed = $this->alertEmbed(AlertChannel::STOCK, '↩️ Vaso Geo (M) — A imprimir');
        $this->assertStringContainsString('Camada descolou.', $this->embedText($embed));
    }

    public function test_an_adjustment_shows_before_and_after(): void
    {
        $order = $this->manualOrder();

        $this->orders->setAdjustment($order, -490, 'Desconto de amiga', $this->admin);

        $text = $this->embedText($this->alertEmbed(AlertChannel::ORDERS, 'Total ajustado'));
        $this->assertStringContainsString('48,90 €', $text);
        $this->assertStringContainsString('44,00 €', $text);
        $this->assertStringContainsString('Desconto de amiga', $text);
    }

    public function test_the_order_is_saved_even_with_discord_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('Discord em baixo'));

        $order = $this->manualOrder();

        $this->assertTrue($order->exists);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_orders_created_through_claude_say_so(): void
    {
        $this->app['request']->server->set('REQUEST_URI', '/mcp');
        $this->app['request']->initialize([], [], [], [], [], ['REQUEST_URI' => '/mcp']);

        $order = $this->manualOrder();

        $text = $this->embedText($this->alertEmbed(AlertChannel::ORDERS, "Nova encomenda {$order->order_number}"));
        $this->assertStringContainsString('Gonçalo via Claude', $text);
    }
}
