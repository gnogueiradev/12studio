<?php

namespace Tests\Feature\Mcp;

use App\Etsy\EtsyOrderImporter;
use App\Jobs\SyncEtsyListing;
use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Etsy\EtsyOrderShipTool;
use App\Mcp\Tools\Etsy\EtsyReceiptUpdateTool;
use App\Mcp\Tools\Read\OrderGetTool;
use App\Models\EtsyReceipt;
use App\Models\McpActivity;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Passport\Passport;
use Tests\Feature\Etsy\FakesEtsy;
use Tests\TestCase;

/**
 * Fase 5 no MCP: enviar uma encomenda da Etsy e marcar um recibo. As duas
 * pedem sempre confirmacao — a primeira faz a Etsy mandar um email ao
 * comprador.
 */
class EtsyShipToolsTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    private User $admin;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Mail::fake();

        $this->configureEtsy();
        $this->connectEtsy(['orders_since' => now()->subHour()]);
        $this->fakeEtsyShopConfig();
        $this->etsyPushedProduct('active');

        Queue::fake([SyncEtsyListing::class]);

        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        app(EtsyOrderImporter::class)->run();
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        Http::fake([
            'etsy.test/v3/application/shops/4242/receipts/3001/tracking' => Http::response($this->etsyReceipt(['is_shipped' => true, 'status' => 'completed'])),
            'etsy.test/v3/application/shops/4242/receipts/3001' => fn (Request $request) => $request->method() === 'PUT'
                ? Http::response($this->etsyReceipt(['is_shipped' => true]))
                : null,
        ]);

        $this->order = Order::query()->where('sales_channel', 'etsy')->sole();
        $this->admin = User::factory()->admin()->create();
    }

    private function reader(): PendingTestResponse
    {
        Passport::actingAs($this->admin, ['mcp:read']);

        return StudioServer::actingAs($this->admin);
    }

    private function writer(): PendingTestResponse
    {
        Passport::actingAs($this->admin, ['mcp:read', 'mcp:write']);

        return StudioServer::actingAs($this->admin);
    }

    /**
     * @return array<int, Request>
     */
    private function shipments(): array
    {
        return $this->etsyRequests('/tracking')->values()->all();
    }

    // ── etsy_order_ship ────────────────────────────────────────────────────

    public function test_shipping_asks_first_and_says_the_buyer_gets_an_email(): void
    {
        $arguments = ['order_number' => $this->order->order_number, 'carrier' => 'CTT', 'tracking_number' => 'RR123456789PT'];

        $this->reader()->tool(EtsyOrderShipTool::class, $arguments)->assertNotRegistered();

        $this->writer()->tool(EtsyOrderShipTool::class, $arguments)
            ->assertHasErrors()
            ->assertSee('email')
            ->assertSee('RR123456789PT')
            ->assertSee('"confirm": true');

        $this->assertSame('ready_to_ship', $this->order->fresh()->status);
        $this->assertSame([], $this->shipments());
    }

    public function test_confirmed_it_ships_here_and_there_once(): void
    {
        $this->writer()->tool(EtsyOrderShipTool::class, [
            'order_id' => $this->order->id,
            'carrier' => 'CTT',
            'tracking_number' => 'RR123456789PT',
            'note_to_buyer' => 'Obrigado pela compra!',
            'confirm' => true,
        ])->assertOk()->assertSee('enviada');

        $order = $this->order->fresh();
        $this->assertSame('shipped', $order->status);
        $this->assertSame('RR123456789PT', $order->tracking_number);
        $this->assertSame('CTT', $order->shipping_method_name);

        $shipments = $this->shipments();
        $this->assertCount(1, $shipments);
        $this->assertSame('RR123456789PT', $shipments[0]['tracking_code']);
        $this->assertSame('Obrigado pela compra!', $shipments[0]['note_to_buyer']);

        $activity = McpActivity::query()->where('tool', 'etsy_order_ship')->latest('id')->first();
        $this->assertSame(['status' => 'ready_to_ship'], $activity->changes['before']);
        $this->assertSame('shipped', $activity->changes['after']['status']);
        $this->assertTrue($activity->changes['after']['told_etsy']);
    }

    public function test_an_order_already_shipped_needs_resend_to_tell_etsy_again(): void
    {
        $this->writer()->tool(EtsyOrderShipTool::class, ['order_id' => $this->order->id, 'confirm' => true])->assertOk();

        // Sem `resend`: a Etsy ja sabe, e outro aviso era outro email.
        $this->writer()->tool(EtsyOrderShipTool::class, ['order_id' => $this->order->id, 'tracking_number' => 'RR777', 'confirm' => true])
            ->assertHasErrors()
            ->assertSee('resend');
        $this->assertCount(1, $this->shipments());

        $this->writer()->tool(EtsyOrderShipTool::class, ['order_id' => $this->order->id, 'tracking_number' => 'RR777', 'resend' => true, 'confirm' => true])
            ->assertOk();

        $this->assertCount(2, $this->shipments());
        $this->assertSame('RR777', $this->shipments()[1]['tracking_code']);
    }

    public function test_only_etsy_orders_are_shipped_this_way(): void
    {
        $other = Order::factory()->create(['sales_channel' => 'vinted', 'status' => 'ready_to_ship', 'payment_status' => 'paid']);

        $this->writer()->tool(EtsyOrderShipTool::class, ['order_id' => $other->id, 'confirm' => true])
            ->assertHasErrors()
            ->assertSee('order_transition');

        $this->assertSame('ready_to_ship', $other->fresh()->status);
    }

    public function test_what_etsy_refuses_is_told_and_the_order_still_ships_here(): void
    {
        // No Http::fake a primeira resposta registada ganha, e a do setUp
        // aceita o envio do 3001: da-se outro numero ao recibo e recusa-se
        // esse.
        EtsyReceipt::query()->update(['etsy_receipt_id' => 3002]);
        Http::fake(['etsy.test/v3/application/shops/4242/receipts/3002/tracking' => Http::response(['error' => 'Insufficient permissions'], 403)]);

        $this->writer()->tool(EtsyOrderShipTool::class, ['order_id' => $this->order->id, 'confirm' => true])
            ->assertOk()
            ->assertSee('Insufficient permissions')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('told_etsy', false)->etc());

        $this->assertSame('shipped', $this->order->fresh()->status);
    }

    // ── etsy_receipt_update ────────────────────────────────────────────────

    public function test_marking_a_receipt_asks_first(): void
    {
        $this->reader()->tool(EtsyReceiptUpdateTool::class, ['receipt_id' => 3001, 'was_shipped' => true])->assertNotRegistered();

        $this->writer()->tool(EtsyReceiptUpdateTool::class, ['receipt_id' => 3001, 'was_shipped' => true])
            ->assertHasErrors()
            ->assertSee('"confirm": true');

        $this->assertNull($this->etsyRequests('/receipts/3001')->first(fn (Request $request): bool => $request->method() === 'PUT'));

        $this->writer()->tool(EtsyReceiptUpdateTool::class, ['receipt_id' => 3001, 'was_shipped' => true, 'confirm' => true])->assertOk();

        $update = $this->etsyRequests('/receipts/3001')->first(fn (Request $request): bool => $request->method() === 'PUT');
        $this->assertSame('true', $update['was_shipped']);
    }

    public function test_marking_needs_something_to_mark(): void
    {
        $this->writer()->tool(EtsyReceiptUpdateTool::class, ['receipt_id' => 3001, 'confirm' => true])->assertHasErrors();
    }

    // ── order_get ──────────────────────────────────────────────────────────

    public function test_the_order_says_whether_etsy_was_told(): void
    {
        $this->writer()->tool(EtsyOrderShipTool::class, ['order_id' => $this->order->id, 'confirm' => true])->assertOk();

        $this->reader()->tool(OrderGetTool::class, ['id' => $this->order->id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('etsy.shipment_sent_to_etsy', true)
                ->where('etsy.shipment_problem', null)
                ->etc());
    }
}
