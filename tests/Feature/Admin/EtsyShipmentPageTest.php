<?php

namespace Tests\Feature\Admin;

use App\Etsy\EtsyOrderImporter;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Etsy\FakesEtsy;
use Tests\TestCase;

/**
 * O envio de uma encomenda da Etsy, visto da pagina da encomenda: o que a
 * Etsy ja sabe, e o botao para lhe mandar o seguimento outra vez.
 */
class EtsyShipmentPageTest extends TestCase
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
        ]);

        $this->order = Order::query()->where('sales_channel', 'etsy')->sole();
        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<int, Request>
     */
    private function shipments(): array
    {
        return $this->etsyRequests('/tracking')->values()->all();
    }

    public function test_the_page_says_what_etsy_knows_about_the_shipment(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.show', $this->order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.etsy.shipmentSentAt', null)
                ->where('order.etsy.shipmentProblem', null)
                ->where('order.etsy.shippedOnEtsyAt', null)
                // As transportadoras que a Etsy conhece, para o campo sugerir.
                ->where('order.etsy.carriers', ['CTT']));

        app(OrderService::class)->transitionOrder($this->order, 'shipped');

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.show', $this->order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.etsy.shipmentSentAt', fn (?string $at): bool => $at !== null)
                ->where('order.etsy.shipmentProblem', null));
    }

    public function test_the_tracking_can_be_sent_again_from_the_page(): void
    {
        $order = app(OrderService::class)->transitionOrder($this->order, 'shipped');
        app(OrderService::class)->setTracking($order, ['tracking_number' => 'RR777', 'shipping_method_name' => 'CTT']);

        $this->actingAs($this->admin)
            ->from(route('admin.encomendas.show', $order))
            ->post(route('admin.encomendas.etsy.envio', $order), ['note_to_buyer' => 'Já tem seguimento.'])
            ->assertRedirect(route('admin.encomendas.show', $order))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        $shipments = $this->shipments();
        $this->assertCount(2, $shipments);
        $this->assertSame('RR777', $shipments[1]['tracking_code']);
        $this->assertSame('Já tem seguimento.', $shipments[1]['note_to_buyer']);
    }

    public function test_an_order_not_yet_shipped_is_not_sent_from_this_button(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.etsy.envio', $this->order))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');

        $this->assertSame([], $this->shipments());
    }

    public function test_what_etsy_refuses_comes_back_as_a_message(): void
    {
        $order = app(OrderService::class)->transitionOrder($this->order, 'shipped');
        EtsyReceipt::query()->update(['etsy_receipt_id' => 3002, 'tracking_pushed_at' => null]);
        Http::fake(['etsy.test/v3/application/shops/4242/receipts/3002/tracking' => Http::response(['error' => 'Insufficient permissions'], 403)]);

        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.etsy.envio', $order))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error')
            ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message): bool => str_contains($message, 'Insufficient permissions'));
    }

    public function test_orders_from_other_channels_have_no_such_button(): void
    {
        $other = Order::factory()->create(['sales_channel' => 'vinted', 'status' => 'shipped', 'payment_status' => 'paid']);

        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.etsy.envio', $other))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');

        $this->assertSame([], $this->shipments());
    }

    public function test_only_admins_send_the_shipment(): void
    {
        $order = app(OrderService::class)->transitionOrder($this->order, 'shipped');

        foreach ([User::factory()->create(), User::factory()->production()->create()] as $user) {
            $this->actingAs($user)->post(route('admin.encomendas.etsy.envio', $order))->assertForbidden();
        }

        $this->assertCount(1, $this->shipments());
    }
}
