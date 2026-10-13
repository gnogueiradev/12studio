<?php

namespace Tests\Feature\Admin;

use App\Etsy\EtsyOrderImporter;
use App\Jobs\SyncEtsyListing;
use App\Models\EtsyReceipt;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Etsy\FakesEtsy;
use Tests\TestCase;

/**
 * As encomendas da Etsy no backoffice: entram na lista como as outras, a
 * pagina mostra o lado da Etsy, e ninguem as regista a mao.
 */
class EtsyOrdersPageTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    private User $admin;

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

        $this->admin = User::factory()->admin()->create();
    }

    private function imported(): Order
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        app(EtsyOrderImporter::class)->run();

        return Order::query()->where('sales_channel', 'etsy')->sole();
    }

    public function test_the_order_page_shows_the_etsy_side(): void
    {
        $order = $this->imported();

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/encomendas/show')
                ->where('order.salesChannel', 'etsy')
                ->where('order.etsy.receiptId', 3001)
                ->where('order.etsy.etsyStatus', 'paid')
                ->where('order.etsy.needsReview', false)
                ->where('order.etsy.buyerMessage', 'Pode embrulhar para oferta?')
                ->where('order.etsy.grossCents', 5430)
                ->where('order.etsy.feesCents', 247)
                ->where('order.etsy.netCents', 5183)
                ->where('order.etsy.url', 'https://www.etsy.com/your/orders/sold?order_id=3001'));
    }

    public function test_an_order_from_another_channel_has_no_etsy_side(): void
    {
        $order = Order::factory()->create(['sales_channel' => 'vinted']);

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.show', $order))
            ->assertInertia(fn (Assert $page) => $page->where('order.etsy', null));
    }

    public function test_the_list_filters_by_the_etsy_channel(): void
    {
        $order = $this->imported();
        Order::factory()->create(['sales_channel' => 'vinted']);

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.index', ['sales_channel' => 'etsy']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.orderNumber', $order->order_number));
    }

    public function test_importing_now_from_the_backoffice(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        $this->actingAs($this->admin)
            ->from(route('admin.encomendas.index'))
            ->post(route('admin.etsy.encomendas.importar'))
            ->assertRedirect(route('admin.encomendas.index'))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        $this->assertSame(1, EtsyReceipt::query()->count());
    }

    public function test_the_orders_list_knows_whether_there_is_a_shop_to_import_from(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.index'))
            ->assertInertia(fn (Assert $page) => $page->where('etsyConnected', true));
    }

    public function test_an_etsy_order_cannot_be_registered_by_hand(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), ['sales_channel' => 'etsy', 'payment_method' => 'etsy'])
            ->assertSessionHasErrors(['sales_channel', 'payment_method']);

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('salesChannels', fn ($channels): bool => ! collect($channels)->contains('etsy')));
    }

    public function test_the_payment_method_of_an_etsy_order_is_not_changed_by_hand_to_etsy_elsewhere(): void
    {
        $other = Order::factory()->create(['sales_channel' => 'vinted', 'payment_status' => 'pending']);

        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.pagamento', $other), ['payment_status' => 'paid', 'payment_method' => 'etsy'])
            ->assertSessionHasErrors('payment_method');
    }

    public function test_only_admins_import(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        foreach ([User::factory()->create(), User::factory()->production()->create()] as $user) {
            $this->actingAs($user)->post(route('admin.etsy.encomendas.importar'))->assertForbidden();
        }

        $this->assertSame(0, EtsyReceipt::query()->count());
    }
}
