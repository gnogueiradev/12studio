<?php

namespace Tests\Feature\Etsy;

use App\Alerts\AlertChannel;
use App\Etsy\EtsyApiException;
use App\Etsy\EtsyOrderImporter;
use App\Etsy\EtsyShipments;
use App\Jobs\PushEtsyShipment;
use App\Jobs\SyncEtsyListing;
use App\Models\EtsyReceipt;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Alerts\FakesDiscord;
use Tests\TestCase;

/**
 * O envio de uma encomenda da Etsy a chegar a Etsy. Cada vez que isto
 * acontece a Etsy manda um email ao comprador: os testes guardam que
 * acontece UMA vez, e nunca sem alguem ter enviado a encomenda.
 */
class EtsyShipmentTest extends TestCase
{
    use FakesDiscord;
    use FakesEtsy;
    use RefreshDatabase;

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
    }

    /**
     * Uma encomenda da Etsy ja importada e pronta a enviar, com a Etsy a
     * aceitar (ou nao) o envio.
     */
    private function order(mixed $shipmentResponse = null): Order
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        app(EtsyOrderImporter::class)->run();
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        Http::fake([
            'etsy.test/v3/application/shops/4242/receipts/3001/tracking' => $shipmentResponse
                ?? Http::response($this->etsyReceipt(['is_shipped' => true, 'status' => 'completed'])),
        ]);

        return Order::query()->where('sales_channel', 'etsy')->sole();
    }

    /**
     * @return array<int, Request>
     */
    private function shipments(): array
    {
        return $this->etsyRequests('/tracking')->values()->all();
    }

    private function ship(Order $order): Order
    {
        return app(OrderService::class)->transitionOrder($order, 'shipped');
    }

    private function receipt(): EtsyReceipt
    {
        return EtsyReceipt::query()->sole();
    }

    // ── Sozinho, ao enviar ─────────────────────────────────────────────────

    public function test_shipping_an_etsy_order_tells_etsy_with_the_tracking(): void
    {
        $order = $this->order();
        app(OrderService::class)->setTracking($order, ['tracking_number' => 'RR123456789PT', 'shipping_method_name' => 'CTT']);
        $this->freezeTime();

        $this->ship($order);

        [$shipment] = $this->shipments();
        $this->assertSame('POST', $shipment->method());
        $this->assertStringEndsWith('/shops/4242/receipts/3001/tracking', $shipment->url());
        // Em JSON, ao contrario de quase tudo o resto.
        $this->assertStringStartsWith('application/json', $shipment->header('Content-Type')[0]);
        $this->assertSame('RR123456789PT', $shipment['tracking_code']);
        $this->assertSame('CTT', $shipment['carrier_name']);

        $receipt = $this->receipt();
        $this->assertSame(now()->timestamp, $receipt->tracking_pushed_at?->timestamp);
        $this->assertNull($receipt->tracking_push_error);
        $this->assertNotNull($receipt->shipped_on_etsy_at);
    }

    public function test_it_is_told_once(): void
    {
        $order = $this->order();
        $order = $this->ship($order);

        // Entregue, ou enviada outra vez por engano: a Etsy ja sabe, e cada
        // aviso a mais era um email a mais ao comprador.
        app(OrderService::class)->transitionOrder($order, 'delivered');

        $this->assertCount(1, $this->shipments());
    }

    public function test_without_tracking_etsy_only_marks_it_as_shipped(): void
    {
        $this->ship($this->order());

        $data = $this->shipments()[0]->data();
        $this->assertArrayNotHasKey('tracking_code', $data);
        $this->assertArrayNotHasKey('carrier_name', $data);
    }

    public function test_a_carrier_etsy_does_not_know_goes_as_other(): void
    {
        $order = $this->order();
        app(OrderService::class)->setTracking($order, ['tracking_number' => 'ZE-42', 'shipping_method_name' => 'Transportadora do Zé']);

        $this->ship($order);

        $this->assertSame('other', $this->shipments()[0]['carrier_name']);
        $this->assertSame('ZE-42', $this->shipments()[0]['tracking_code']);
    }

    public function test_the_carrier_is_matched_whatever_the_case(): void
    {
        $order = $this->order();
        app(OrderService::class)->setTracking($order, ['tracking_number' => 'RR1', 'shipping_method_name' => ' ctt ']);

        $this->ship($order);

        $this->assertSame('CTT', $this->shipments()[0]['carrier_name']);
    }

    public function test_it_goes_through_the_queue(): void
    {
        $order = $this->order();
        Queue::fake([PushEtsyShipment::class, SyncEtsyListing::class]);

        $this->ship($order);

        Queue::assertPushed(PushEtsyShipment::class, fn (PushEtsyShipment $job): bool => $job->orderId === $order->id);
        $this->assertSame([], $this->shipments());
    }

    public function test_what_etsy_already_shows_as_shipped_is_not_pushed_back(): void
    {
        $this->order();

        // Marcada como enviada no gestor da loja: a importacao espelha-a ca.
        $this->fakeEtsyReceipts([$this->etsyReceipt([
            'is_shipped' => true,
            'status' => 'completed',
            'update_timestamp' => now()->addMinute()->timestamp,
            'shipments' => [['receipt_shipping_id' => 1, 'carrier_name' => 'ctt', 'tracking_code' => 'RR999', 'shipment_notification_timestamp' => now()->timestamp]],
        ])]);
        app(EtsyOrderImporter::class)->run();

        $this->assertSame('shipped', Order::query()->sole()->status);
        $this->assertSame([], $this->shipments());
    }

    public function test_orders_from_other_channels_tell_etsy_nothing(): void
    {
        $this->order();
        $other = Order::factory()->create(['sales_channel' => 'vinted', 'status' => 'ready_to_ship', 'payment_status' => 'paid']);

        $this->ship($other);

        $this->assertSame([], $this->shipments());
    }

    public function test_with_the_switch_off_nothing_is_told(): void
    {
        $order = $this->order();
        config(['etsy.enabled' => false]);

        $this->ship($order);

        $this->assertSame([], $this->shipments());
    }

    public function test_no_email_of_ours_goes_to_the_buyer(): void
    {
        $this->ship($this->order());

        Mail::assertNothingOutgoing();
    }

    // ── Quando a Etsy diz que nao ──────────────────────────────────────────

    public function test_a_refusal_lets_the_order_go_on_and_tells_the_owner(): void
    {
        $order = $this->order(Http::response(['error' => 'Insufficient permissions'], 403));
        $this->fakeDiscord();

        $shipped = $this->ship($order);

        // A peca saiu pela porta: ca a encomenda segue.
        $this->assertSame('shipped', $shipped->status);

        $receipt = $this->receipt();
        $this->assertNull($receipt->tracking_pushed_at);
        $this->assertStringContainsString('Insufficient permissions', (string) $receipt->tracking_push_error);

        $embed = $this->alertEmbed(AlertChannel::ORDERS, 'Etsy');
        $this->assertStringContainsString($order->order_number, (string) json_encode($embed));
    }

    public function test_a_failed_push_is_not_tried_again_by_itself(): void
    {
        $order = $this->order(Http::response(['error' => 'Insufficient permissions'], 403));
        $order = $this->ship($order);

        app(OrderService::class)->transitionOrder($order, 'delivered');

        $this->assertCount(1, $this->shipments());
    }

    // ── A pedido ───────────────────────────────────────────────────────────

    public function test_tracking_added_later_is_only_sent_when_asked(): void
    {
        $order = $this->ship($this->order());

        app(OrderService::class)->setTracking($order, ['tracking_number' => 'RR777', 'shipping_method_name' => 'CTT']);
        $this->assertCount(1, $this->shipments());

        // A Etsy volta a avisar o comprador: so com alguem a pedir.
        app(EtsyShipments::class)->push($order->fresh(), ['resend' => true, 'note_to_buyer' => 'Já tem seguimento!']);

        $shipments = $this->shipments();
        $this->assertCount(2, $shipments);
        $this->assertSame('RR777', $shipments[1]['tracking_code']);
        $this->assertSame('Já tem seguimento!', $shipments[1]['note_to_buyer']);
    }

    public function test_asking_again_without_saying_resend_does_nothing(): void
    {
        $order = $this->ship($this->order());

        app(EtsyShipments::class)->push($order->fresh());

        $this->assertCount(1, $this->shipments());
    }

    public function test_asked_for_directly_a_refusal_is_an_error_with_what_etsy_said(): void
    {
        $order = $this->order(Http::response(['error' => 'Insufficient permissions'], 403));
        $order->update(['status' => 'shipped', 'shipped_at' => now()]);

        $this->expectException(EtsyApiException::class);
        $this->expectExceptionMessage('Insufficient permissions');

        app(EtsyShipments::class)->push($order);
    }

    public function test_shipping_in_one_go_sends_once_and_moves_the_order(): void
    {
        $order = $this->order();
        $admin = User::factory()->admin()->create();

        $shipped = app(EtsyShipments::class)->ship($order, [
            'tracking_number' => 'RR123456789PT',
            'carrier' => 'CTT',
            'note_to_buyer' => 'Obrigado!',
        ], $admin);

        $this->assertSame('shipped', $shipped->status);
        $this->assertSame('RR123456789PT', $shipped->tracking_number);
        // Uma so vez: o gancho do "enviada" nao volta a mandar.
        $shipments = $this->shipments();
        $this->assertCount(1, $shipments);
        $this->assertSame('Obrigado!', $shipments[0]['note_to_buyer']);
    }

    public function test_a_partly_refunded_order_still_ships_here_after_etsy_was_told(): void
    {
        // Reembolso parcial (um desconto dado depois): a peca vai na mesma.
        // Sem isto a Etsy era avisada e a encomenda ficava parada ca, porque
        // "parcialmente reembolsada" nao conta como paga.
        $order = $this->order();
        app(OrderService::class)->setPaymentStatus($order, 'partially_refunded');

        $shipped = app(EtsyShipments::class)->ship($order->fresh(), ['tracking_number' => 'RR1', 'carrier' => 'CTT'], null);

        $this->assertSame('shipped', $shipped->status);
        $this->assertCount(1, $this->shipments());
    }

    public function test_setting_the_tracking_leaves_the_note_and_the_tags_alone(): void
    {
        $order = $this->order();
        $order->update(['admin_note' => 'Embrulhar com cuidado.']);
        app(OrderService::class)->updateDetails($order, ['admin_note' => 'Embrulhar com cuidado.', 'tags' => ['urgente']]);

        app(OrderService::class)->setTracking($order, ['tracking_number' => 'RR1', 'tracking_url' => 'https://ctt.pt/RR1']);

        $order->refresh();
        $this->assertSame('Embrulhar com cuidado.', $order->admin_note);
        $this->assertSame(['urgente'], $order->tags()->pluck('name')->all());
        $this->assertSame('RR1', $order->tracking_number);
        $this->assertSame('https://ctt.pt/RR1', $order->tracking_url);
    }

    // ── Marcar o recibo ────────────────────────────────────────────────────

    public function test_a_receipt_can_be_marked_without_a_shipment(): void
    {
        $this->order();
        Http::fake(['etsy.test/v3/application/shops/4242/receipts/3001' => fn (Request $request) => $request->method() === 'PUT'
            ? Http::response($this->etsyReceipt(['is_shipped' => true]))
            : null]);

        app(EtsyShipments::class)->markReceipt(3001, shipped: true);

        $update = $this->etsyRequests('/receipts/3001')->first(fn (Request $request): bool => $request->method() === 'PUT');
        $this->assertStringStartsWith('application/x-www-form-urlencoded', $update->header('Content-Type')[0]);
        $this->assertSame('true', $update['was_shipped']);
        $this->assertArrayNotHasKey('was_paid', $update->data());
    }
}
