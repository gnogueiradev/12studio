<?php

namespace Tests\Feature\Etsy;

use App\Alerts\AlertChannel;
use App\Etsy\EtsyException;
use App\Etsy\EtsyListingLifecycle;
use App\Etsy\EtsyListingSync;
use App\Etsy\EtsyOrderImporter;
use App\Etsy\EtsyShipments;
use App\Jobs\DeleteEtsyListing;
use App\Jobs\SyncEtsyListing;
use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Etsy\EtsyListingPushTool;
use App\Mcp\Tools\Etsy\EtsyListingSaveTool;
use App\Mcp\Tools\Read\OrderGetTool;
use App\Models\EtsyListing;
use App\Models\EtsyReceipt;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Passport\Passport;
use RuntimeException;
use Tests\Feature\Alerts\FakesDiscord;
use Tests\TestCase;

/**
 * O que a revisao independente das fases 1B a 5 apanhou, um teste por
 * achado. Cada um reproduz o cenario que ela descreveu.
 */
class EtsyReviewFixesTest extends TestCase
{
    use FakesDiscord;
    use FakesEtsy;
    use RefreshDatabase;

    private Product $product;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Mail::fake();

        $this->configureEtsy();
        $this->connectEtsy(['orders_since' => now()->subHour()]);
        $this->fakeEtsyShopConfig();

        $this->product = $this->etsyPushedProduct('active');
        $this->admin = User::factory()->admin()->create();

        Queue::fake([SyncEtsyListing::class]);
    }

    private function import(): void
    {
        app(EtsyOrderImporter::class)->run();
    }

    private function stock(string $sku = 'VASO-P'): int
    {
        return (int) Variant::query()->where('sku', $sku)->value('stock');
    }

    private function order(): Order
    {
        return Order::query()->where('sales_channel', 'etsy')->sole();
    }

    private function listing(): EtsyListing
    {
        return EtsyListing::query()->sole();
    }

    /**
     * @return array<int, string>
     */
    private function writes(): array
    {
        return $this->etsyRequests()
            ->filter(fn (Request $request): bool => $request->method() !== 'GET')
            ->map(fn (Request $request): string => $request->method().' '.str_replace(self::ETSY_API, '', $request->url()))
            ->values()
            ->all();
    }

    private function writer(): PendingTestResponse
    {
        Passport::actingAs($this->admin, ['mcp:read', 'mcp:write']);

        return StudioServer::actingAs($this->admin);
    }

    private function canceled(): array
    {
        return $this->etsyReceipt(['status' => 'canceled', 'update_timestamp' => now()->addMinute()->timestamp]);
    }

    // ── C1: stock que nunca saiu nao volta ─────────────────────────────────

    public function test_cancelling_an_order_that_never_took_stock_gives_none_back(): void
    {
        // A ultima unidade vendeu-se no site; a Etsy vendeu duas a seguir.
        Variant::query()->where('sku', 'VASO-P')->update(['stock' => 0]);
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->import();
        $this->assertTrue($this->order()->stock_issue);
        $this->assertSame(0, $this->stock());

        $this->fakeEtsyReceipts([$this->canceled()]);
        $this->import();

        // Sem isto apareciam duas unidades que nunca existiram — e o anuncio
        // voltava a venda com elas.
        $this->assertSame('cancelled', $this->order()->status);
        $this->assertSame(0, $this->stock());
    }

    public function test_a_partly_short_sale_takes_what_there_is_and_gives_back_only_that(): void
    {
        Variant::query()->where('sku', 'VASO-P')->update(['stock' => 1]);
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->import();

        // A unidade que havia e desta encomenda: nao pode continuar a venda.
        $order = $this->order();
        $this->assertTrue($order->stock_issue);
        $this->assertSame(0, $this->stock());
        $this->assertSame(-1, (int) StockMovement::query()->where('order_id', $order->id)->sum('delta'));

        $this->fakeEtsyReceipts([$this->canceled()]);
        $this->import();

        $this->assertSame(1, $this->stock());
    }

    public function test_cancelling_twice_never_gives_the_stock_back_twice(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->import();
        $order = $this->order();

        app(OrderService::class)->transitionOrder($order, 'cancelled');
        // Um segundo processo que ainda via a encomenda por cancelar.
        $order->update(['status' => 'ready_to_ship']);
        app(OrderService::class)->transitionOrder($order, 'cancelled');

        $this->assertSame(5, $this->stock());
    }

    // ── I1: o nome inteiro do comprador nao sai pelo MCP ───────────────────

    public function test_order_get_shows_only_the_short_name_of_an_etsy_buyer(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->import();

        $this->writer()->tool(OrderGetTool::class, ['id' => $this->order()->id])
            ->assertOk()
            ->assertSee('<dados_cliente>Ana S.</dados_cliente>')
            ->assertDontSee('Ana Maria Silva');
    }

    // ── I2: um recibo que rebenta nao fica para tras ───────────────────────

    public function test_a_receipt_that_blows_up_is_tried_again_and_named(): void
    {
        $this->fakeDiscord();
        Variant::query()->where('sku', 'VASO-P')->update(['stock' => 10]);
        $receipts = [
            $this->etsyReceipt(['receipt_id' => 3001, 'update_timestamp' => now()->timestamp]),
            $this->etsyReceipt(['receipt_id' => 3002, 'update_timestamp' => now()->timestamp + 1]),
        ];
        $this->fakeEtsyReceipts($receipts);

        // A base de dados ocupada, so para o primeiro recibo, so desta vez.
        $broken = true;
        Order::creating(function (Order $order) use (&$broken): void {
            if ($broken && $order->external_order_reference === '3001') {
                throw new RuntimeException('database is locked: Ana Maria Silva, Rua das Flores 12');
            }
        });

        $result = app(EtsyOrderImporter::class)->run();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['imported']);

        // Diz QUAL recibo, e nao leva para o Discord o que a excecao trazia.
        $embed = (string) json_encode($this->alertEmbed(AlertChannel::SYSTEM, 'Etsy'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('3001', $embed);
        $this->assertStringNotContainsString('Ana Maria', $embed);
        $this->assertStringNotContainsString('Rua das Flores', $embed);

        // Passou: na vez seguinte entra, mesmo ja havendo um recibo mais novo.
        $broken = false;
        $this->fakeEtsyReceipts($receipts);
        app(EtsyOrderImporter::class)->run();

        $this->assertSame(['3001', '3002'], Order::query()->orderBy('external_order_reference')->pluck('external_order_reference')->all());
        $this->assertSame(6, $this->stock());
    }

    // ── I3: o estado que ca esta pode ter horas ────────────────────────────

    public function test_a_listing_the_owner_turned_off_on_etsy_is_not_taken_for_ours(): void
    {
        // Desligado a mao no site da Etsy; ca ainda diz "a venda".
        $this->etsyRemoteState = 'inactive';
        Variant::query()->where('product_id', $this->product->id)->update(['stock' => 0]);

        app(EtsyListingLifecycle::class)->sync($this->listing());

        $this->assertSame([], $this->writes());
        $this->assertSame('inactive', $this->listing()->state);
        // Nao fomos nos: quando voltar a haver stock, fica desligado.
        $this->assertNull($this->listing()->auto_deactivated_at);

        Variant::query()->where('product_id', $this->product->id)->update(['stock' => 4]);
        $this->fakeEtsyListingWrites();

        $this->assertSame(EtsyListingLifecycle::SKIPPED, app(EtsyListingLifecycle::class)->sync($this->listing()));
        $this->assertSame([], $this->writes());
    }

    public function test_before_putting_a_listing_back_on_sale_the_real_state_is_checked(): void
    {
        Variant::query()->where('product_id', $this->product->id)->update(['stock' => 0]);
        app(EtsyListingLifecycle::class)->sync($this->listing());
        $this->assertNotNull($this->listing()->auto_deactivated_at);

        // Entretanto expirou la. Por "active" era renovar — e renovar paga-se.
        $this->etsyRemoteState = 'expired';
        Variant::query()->where('product_id', $this->product->id)->update(['stock' => 4]);
        $this->fakeEtsyListingWrites();

        app(EtsyListingLifecycle::class)->sync($this->listing());

        $states = $this->etsyRequests()->map(fn (Request $request) => $request->data()['state'] ?? null)->filter()->all();
        $this->assertNotContains('active', $states);
        $this->assertSame('expired', $this->listing()->state);
        $this->assertNull($this->listing()->auto_deactivated_at);
    }

    public function test_a_listing_that_sold_out_on_etsy_is_not_marked_as_turned_off_by_us(): void
    {
        // A Etsy vendeu a ultima: la esta "sold_out", ca ainda "active".
        $this->etsyRemoteState = 'sold_out';
        Variant::query()->where('product_id', $this->product->id)->update(['stock' => 0]);

        app(EtsyListingLifecycle::class)->sync($this->listing());

        $this->assertSame([], $this->writes());
        $this->assertSame('sold_out', $this->listing()->state);
        $this->assertNull($this->listing()->auto_deactivated_at);
    }

    // ── I4: guardar num anuncio a venda segue sozinho ──────────────────────

    public function test_saving_content_of_a_listing_on_sale_says_it_goes_live_and_asks(): void
    {
        $arguments = ['product_id' => $this->product->id, 'title' => 'Spiral Vase — 3D printed'];

        // A sincronizacao leva isto para a Etsy em minutos, sem mais nenhum
        // "enviar": quem guarda tem de o saber.
        $this->writer()->tool(EtsyListingSaveTool::class, $arguments)
            ->assertHasErrors()
            ->assertSee('à venda')
            ->assertSee('"confirm": true');
        $this->assertNull($this->listing()->title);

        $this->writer()->tool(EtsyListingSaveTool::class, [...$arguments, 'confirm' => true])->assertOk();
        $this->assertSame('Spiral Vase — 3D printed', $this->listing()->title);
    }

    public function test_saving_content_of_a_draft_or_an_unfollowed_listing_does_not_ask(): void
    {
        $this->listing()->update(['sync_enabled' => false]);

        $this->writer()->tool(EtsyListingSaveTool::class, ['product_id' => $this->product->id, 'title' => 'Um'])->assertOk();

        $this->listing()->update(['sync_enabled' => true, 'state' => 'draft']);

        $this->writer()->tool(EtsyListingSaveTool::class, ['product_id' => $this->product->id, 'title' => 'Dois'])->assertOk();

        // Desligar a sincronizacao e precisamente o que impede o envio.
        $this->listing()->update(['state' => 'active']);

        $this->writer()->tool(EtsyListingSaveTool::class, ['product_id' => $this->product->id, 'title' => 'Tres', 'sync_enabled' => false])->assertOk();
    }

    public function test_the_page_says_when_saving_goes_straight_to_etsy(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.produtos.etsy.guardar', $this->product), ['title' => 'Spiral Vase'])
            ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message): bool => str_contains($message, 'segue para a Etsy'));

        $this->listing()->update(['state' => 'draft']);

        $this->actingAs($this->admin)
            ->put(route('admin.produtos.etsy.guardar', $this->product), ['title' => 'Spiral Vase 2'])
            ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message): bool => str_contains($message, 'nada foi para a Etsy'));
    }

    // ── m2, m3: o aviso de envio que se calava, e o que saia sem pedir ─────

    public function test_a_shipment_that_never_reached_etsy_is_still_announced_when_the_order_ships(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->import();
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        $down = true;
        Http::fake(['etsy.test/v3/application/shops/4242/receipts/3001/tracking' => function () use (&$down) {
            if ($down) {
                throw new ConnectionException('timeout');
            }

            return Http::response($this->etsyReceipt(['is_shipped' => true]));
        }]);

        try {
            app(EtsyShipments::class)->ship($this->order(), ['tracking_number' => 'RR1', 'carrier' => 'CTT'], $this->admin);
            $this->fail('Devia ter lancado.');
        } catch (EtsyException) {
            // A Etsy nem respondeu: ca nada mudou, alem do seguimento gravado.
        }

        $this->assertSame('ready_to_ship', $this->order()->status);

        $down = false;
        app(OrderService::class)->transitionOrder($this->order(), 'shipped');

        // A tentativa que nao chegou a Etsy nao conta como "ja tentado".
        $this->assertNotNull(EtsyReceipt::query()->sole()->tracking_pushed_at);
    }

    public function test_a_receipt_marked_as_shipped_by_hand_is_not_announced_again(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->import();
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        Http::fake([
            'etsy.test/v3/application/shops/4242/receipts/3001/tracking' => Http::response($this->etsyReceipt(['is_shipped' => true])),
            'etsy.test/v3/application/shops/4242/receipts/3001' => fn (Request $request) => $request->method() === 'PUT'
                ? Http::response($this->etsyReceipt(['is_shipped' => true]))
                : null,
        ]);

        app(EtsyShipments::class)->markReceipt(3001, shipped: true);
        app(OrderService::class)->transitionOrder($this->order(), 'shipped');

        // A Etsy ja a mostra enviada: outro aviso era um email que ninguem pediu.
        $this->assertCount(0, $this->etsyRequests('/tracking'));
    }

    // ── m7: dois "Enviar" ao mesmo tempo ───────────────────────────────────

    public function test_a_second_push_of_the_same_product_at_the_same_time_is_refused(): void
    {
        $other = Product::factory()->create(['name' => 'Suporte']);
        $lock = Cache::lock(EtsyListingSync::pushLock($other->id), 60);
        $lock->get();

        try {
            app(EtsyListingSync::class)->push($other);
            $this->fail('Devia ter lancado.');
        } catch (EtsyException $exception) {
            $this->assertStringContainsString('já está a ser enviado', $exception->getMessage());
        } finally {
            $lock->release();
        }

        $this->assertSame([], $this->writes());
    }

    // ── O que ela deixou para decidir ──────────────────────────────────────

    public function test_a_full_refund_after_shipping_closes_the_order_as_refunded(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->import();
        $this->order()->update(['status' => 'shipped', 'shipped_at' => now()]);

        $this->fakeEtsyReceipts([$this->etsyReceipt(['status' => 'fully refunded', 'update_timestamp' => now()->addMinute()->timestamp])]);
        $this->import();

        $this->assertSame('refunded', $this->order()->status);
        // A peca ja saiu: o stock nao volta sozinho.
        $this->assertSame(3, $this->stock());
    }

    public function test_a_product_restored_before_the_queue_ran_keeps_its_listing(): void
    {
        Queue::fake([DeleteEtsyListing::class, SyncEtsyListing::class]);
        app(ProductService::class)->delete($this->product);
        app(ProductService::class)->restore(Product::withTrashed()->findOrFail($this->product->id));

        // Apagado por engano e recuperado logo a seguir: a tarefa que estava
        // na fila ja nao tem o que apagar.
        (new DeleteEtsyListing($this->listing()->id))->handle(app(EtsyListingLifecycle::class));

        $this->assertSame([], $this->writes());
        $this->assertSame(7100, $this->listing()->etsy_listing_id);
    }

    public function test_the_tracking_of_an_etsy_order_stays_out_of_discord(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->import();
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        Http::fake(['etsy.test/v3/application/shops/4242/receipts/3001/tracking' => Http::response($this->etsyReceipt(['is_shipped' => true]))]);
        $this->fakeDiscord();
        app(OrderService::class)->setTracking($this->order(), ['tracking_number' => 'RR123456789PT', 'tracking_url' => 'https://ctt.pt/RR123456789PT']);

        app(OrderService::class)->transitionOrder($this->order(), 'shipped');

        // Com o numero, o site da transportadora mostra para onde foi.
        $embed = (string) json_encode($this->alertEmbed(AlertChannel::ORDERS, 'Enviada'));
        $this->assertStringNotContainsString('RR123456789PT', $embed);
    }

    public function test_pushing_to_a_sold_out_listing_asks_first(): void
    {
        $this->listing()->update(['state' => 'sold_out']);

        // Mexer num esgotado pode renova-lo, e renovar paga-se.
        $this->writer()->tool(EtsyListingPushTool::class, ['product_id' => $this->product->id])
            ->assertHasErrors()
            ->assertSee('esgotado')
            ->assertSee('"confirm": true');

        $this->assertSame([], $this->writes());
    }
}
