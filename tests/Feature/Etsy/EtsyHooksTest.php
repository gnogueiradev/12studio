<?php

namespace Tests\Feature\Etsy;

use App\Alerts\AlertChannel;
use App\Jobs\DeleteEtsyListing;
use App\Jobs\SyncEtsyListing;
use App\Models\EtsyListing;
use App\Models\Product;
use App\Models\Variant;
use App\Services\ProductService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Alerts\FakesDiscord;
use Tests\TestCase;

/**
 * Onde o 12studio avisa a Etsy sem ninguem pedir: o stock que mexe e o
 * produto que e apagado. O que nao pode acontecer e o contrario — a Etsy em
 * baixo estragar uma venda ou um "apagar" de ca.
 */
class EtsyHooksTest extends TestCase
{
    use FakesDiscord;
    use FakesEtsy;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->configureEtsy();
        $this->connectEtsy();
        $this->fakeEtsyShopConfig();
    }

    private function variant(string $sku = 'VASO-P'): Variant
    {
        return Variant::query()->where('sku', $sku)->sole();
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

    // ── Stock ──────────────────────────────────────────────────────────────

    public function test_a_stock_movement_schedules_a_sync_of_the_listing(): void
    {
        $this->etsyPushedProduct('active');
        $listing = EtsyListing::query()->sole();
        Queue::fake([SyncEtsyListing::class]);

        app(StockService::class)->decrement($this->variant(), 1, 'manual_adjust');
        app(StockService::class)->increment($this->variant('VASO-B'), 2, 'manual_adjust');

        // Com uns segundos de atraso: uma encomenda de tres linhas mexe no
        // stock tres vezes e so precisa de um envio.
        Queue::assertPushed(SyncEtsyListing::class, fn (SyncEtsyListing $job): bool => $job->listingId === $listing->id && $job->delay !== null);
    }

    public function test_products_that_are_not_on_etsy_schedule_nothing(): void
    {
        $this->etsyReadyProduct();
        EtsyListing::query()->create(['product_id' => Product::query()->sole()->id, 'title' => 'So preparado']);
        Queue::fake([SyncEtsyListing::class]);

        app(StockService::class)->decrement($this->variant(), 1, 'manual_adjust');

        Queue::assertNothingPushed();
    }

    public function test_with_the_switch_off_nothing_is_scheduled(): void
    {
        $this->etsyPushedProduct('active');
        config(['etsy.enabled' => false]);
        Queue::fake([SyncEtsyListing::class]);

        app(StockService::class)->decrement($this->variant(), 1, 'manual_adjust');

        Queue::assertNothingPushed();
    }

    public function test_from_the_sale_to_etsy_end_to_end(): void
    {
        $this->etsyPushedProduct('active');

        // Com a fila dos testes (sync) a tarefa corre ja.
        app(StockService::class)->decrement($this->variant(), 2, 'manual_adjust');

        $inventory = $this->etsyRequests('/inventory')->last();
        $this->assertSame(3, $inventory['products'][0]['offerings'][0]['quantity']);
    }

    public function test_etsy_being_down_never_breaks_a_stock_movement(): void
    {
        $this->etsyPushedProduct('active');
        $this->fakeEtsyListingWrites([
            'update' => Http::response(['error' => 'Service unavailable'], 503),
        ]);

        $this->assertTrue(app(StockService::class)->decrement($this->variant(), 1, 'manual_adjust'));

        $this->assertSame(4, $this->variant()->stock);
        $this->assertStringContainsString('Service unavailable', (string) EtsyListing::query()->sole()->last_error);
    }

    // ── Apagar o produto ───────────────────────────────────────────────────

    public function test_deleting_a_product_deletes_its_listing_on_etsy(): void
    {
        $product = $this->etsyPushedProduct('active');

        app(ProductService::class)->delete($product);

        $this->assertSame(['DELETE /listings/7100'], $this->writes());
        $listing = EtsyListing::query()->sole();
        $this->assertNull($listing->etsy_listing_id);
        $this->assertSame('deleted', $listing->state);
    }

    public function test_the_deletion_goes_through_the_queue(): void
    {
        $product = $this->etsyPushedProduct('active');
        $listing = EtsyListing::query()->sole();
        Queue::fake([DeleteEtsyListing::class]);

        app(ProductService::class)->delete($product);

        Queue::assertPushed(DeleteEtsyListing::class, fn (DeleteEtsyListing $job): bool => $job->listingId === $listing->id);
        $this->assertSame([], $this->writes());
    }

    public function test_a_product_without_a_listing_on_etsy_deletes_as_before(): void
    {
        $product = $this->etsyReadyProduct();
        Queue::fake([DeleteEtsyListing::class]);

        app(ProductService::class)->delete($product);

        Queue::assertNothingPushed();
        $this->assertSoftDeleted($product);
    }

    public function test_when_etsy_fails_the_product_is_deleted_anyway_and_the_owner_is_told(): void
    {
        $product = $this->etsyPushedProduct('active');
        $this->fakeDiscord();
        $this->fakeEtsyListingWrites(['delete' => Http::response(['error' => 'Service unavailable'], 503)]);

        app(ProductService::class)->delete($product);

        $this->assertSoftDeleted($product);

        // O anuncio continua la, e fica dito: ca, e no #sistema.
        $listing = EtsyListing::query()->sole();
        $this->assertSame(7100, $listing->etsy_listing_id);
        $this->assertStringContainsString('Service unavailable', (string) $listing->last_error);

        $embed = $this->alertEmbed(AlertChannel::SYSTEM, 'Etsy');
        $this->assertStringContainsString('Vaso Espiral', json_encode($embed, JSON_UNESCAPED_UNICODE));
    }

    public function test_restoring_the_product_does_not_bring_the_listing_back(): void
    {
        $product = $this->etsyPushedProduct('active');
        app(ProductService::class)->delete($product);
        $this->fakeEtsyListingWrites();

        app(ProductService::class)->restore($product->fresh() ?? Product::withTrashed()->findOrFail($product->id));

        $this->assertSame([], $this->writes());
        $this->assertNull(EtsyListing::query()->sole()->etsy_listing_id);
    }
}
