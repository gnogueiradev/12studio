<?php

namespace Tests\Feature\Mcp;

use App\Etsy\EtsySettings;
use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Etsy\EtsyListingPreviewTool;
use App\Mcp\Tools\Etsy\EtsyListingPushTool;
use App\Mcp\Tools\Etsy\EtsyListingSaveTool;
use App\Mcp\Tools\Etsy\EtsyPricingPreviewTool;
use App\Models\EtsyListing;
use App\Models\EtsyVariant;
use App\Models\McpActivity;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Passport\Passport;
use Tests\Feature\Etsy\FakesEtsy;
use Tests\TestCase;

/**
 * Fase 2 no MCP: ver o que iria para a Etsy, acertar o conteudo e os precos
 * do anuncio, e envia-lo.
 */
class EtsyListingToolsTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->configureEtsy();
        $this->connectEtsy();
        $this->fakeEtsyShopConfig();

        $this->admin = User::factory()->admin()->create();
        $this->product = $this->etsyReadyProduct();
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

    private function writes(): int
    {
        return $this->etsyRequests()->filter(fn (Request $request): bool => $request->method() !== 'GET')->count();
    }

    private function variant(string $sku): Variant
    {
        return Variant::query()->where('sku', $sku)->sole();
    }

    // ── etsy_listing_preview ───────────────────────────────────────────────

    public function test_the_preview_shows_what_would_be_sent_without_sending_it(): void
    {
        $this->reader()->tool(EtsyListingPreviewTool::class, ['product_id' => $this->product->id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('on_etsy', false)
                ->where('ready', true)
                ->where('blockers', [])
                ->where('listing.title', 'Vaso Espiral')
                ->where('listing.taxonomy_id', 1234)
                ->where('variants.0.sku', 'VASO-P')
                ->where('variants.0.label', 'Preto')
                ->where('variants.0.price.cents', 2490)
                ->where('variants.0.quantity', 5)
                ->etc());

        $this->assertSame(0, $this->writes());
    }

    public function test_the_preview_says_what_stops_the_listing(): void
    {
        Variant::query()->where('product_id', $this->product->id)->update(['stock' => 0]);

        $this->reader()->tool(EtsyListingPreviewTool::class, ['product_id' => $this->product->id])
            ->assertOk()
            ->assertSee('stock')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('ready', false)->etc());
    }

    public function test_compared_with_etsy_it_lists_what_is_different(): void
    {
        EtsyListing::query()->create(['product_id' => $this->product->id, 'etsy_listing_id' => 7001, 'state' => 'draft']);
        Http::fake(['etsy.test/v3/application/listings/7001*' => Http::response($this->etsyListing([
            'title' => 'Spiral Vase — 3D printed',
            'skus' => ['VASO-P', 'VASO-B'],
            'inventory' => ['products' => [
                ['product_id' => 9101, 'sku' => 'VASO-P', 'offerings' => [['offering_id' => 1, 'quantity' => 9, 'is_enabled' => true, 'price' => ['amount' => 1990, 'divisor' => 100, 'currency_code' => 'EUR']]]],
                ['product_id' => 9102, 'sku' => 'VASO-B', 'offerings' => [['offering_id' => 2, 'quantity' => 3, 'is_enabled' => true, 'price' => ['amount' => 2690, 'divisor' => 100, 'currency_code' => 'EUR']]]],
            ]],
        ]))]);

        // O que la esta escrito a mao e o que o 12studio mandaria por cima.
        $this->reader()->tool(EtsyListingPreviewTool::class, ['product_id' => $this->product->id, 'compare' => true])
            ->assertOk()
            ->assertSee('Spiral Vase')
            ->assertSee('19,90')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('differences.0.field', 'title')
                ->where('differences.0.on_etsy', 'Spiral Vase — 3D printed')
                ->where('differences.0.would_send', 'Vaso Espiral')
                ->etc());
    }

    // ── etsy_pricing_preview ───────────────────────────────────────────────

    public function test_the_pricing_preview_puts_site_and_etsy_side_by_side(): void
    {
        app(EtsySettings::class)->save(['fee_percentage_bp' => 1000, 'fee_fixed_cents' => 50]);

        $this->reader()->tool(EtsyPricingPreviewTool::class, ['product_id' => $this->product->id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('variants.0.sku', 'VASO-P')
                ->where('variants.0.price_source', 'site')
                ->where('variants.0.site.price.cents', 2490)
                ->where('variants.0.etsy.fees.cents', 299)
                ->where('variants.0.etsy.net.cents', 1725)
                ->where('assumptions.fee_percent', '10')
                ->etc());

        Http::assertNothingSent();
    }

    // ── etsy_listing_save ──────────────────────────────────────────────────

    public function test_saving_keeps_the_content_here_and_leaves_a_trail(): void
    {
        $this->writer()->tool(EtsyListingSaveTool::class, [
            'product_id' => $this->product->id,
            'title' => 'Spiral Vase — 3D printed',
            'tags' => ['vase', 'decor'],
            'variants' => [['variant_id' => $this->variant('VASO-P')->id, 'etsy_price' => '27,90']],
        ])->assertOk()->assertSee('etsy_listing_push');

        $listing = EtsyListing::query()->sole();
        $this->assertSame('Spiral Vase — 3D printed', $listing->title);
        $this->assertSame(2790, EtsyVariant::query()->sole()->etsy_price_cents);
        $this->assertSame(0, $this->writes());

        $activity = McpActivity::query()->where('tool', 'etsy_listing_save')->sole();
        $this->assertSame($this->product->id, $activity->changes['product_id']);
        $this->assertSame(['title', 'tags', 'variants'], $activity->changes['fields']);
    }

    public function test_a_strange_etsy_price_asks_first(): void
    {
        $arguments = [
            'product_id' => $this->product->id,
            'variants' => [['variant_id' => $this->variant('VASO-P')->id, 'etsy_price' => '2,49']],
        ];

        // De 24,90 para 2,49: mais parece uma virgula fora do sitio.
        $this->writer()->tool(EtsyListingSaveTool::class, $arguments)
            ->assertHasErrors()
            ->assertSee('"confirm": true');
        $this->assertSame(0, EtsyVariant::query()->count());

        $this->writer()->tool(EtsyListingSaveTool::class, [...$arguments, 'confirm' => true])->assertOk();
        $this->assertSame(249, EtsyVariant::query()->sole()->etsy_price_cents);
    }

    public function test_the_same_limits_of_the_backoffice_apply(): void
    {
        $this->writer()->tool(EtsyListingSaveTool::class, [
            'product_id' => $this->product->id,
            'title' => str_repeat('a', 141),
        ])->assertHasErrors();

        $this->assertSame(0, EtsyListing::query()->count());
    }

    // ── etsy_listing_push ──────────────────────────────────────────────────

    public function test_pushing_a_new_listing_creates_a_draft_without_asking(): void
    {
        $this->fakeEtsyListingWrites();

        $this->reader()->tool(EtsyListingPushTool::class, ['product_id' => $this->product->id])->assertNotRegistered();

        $this->writer()->tool(EtsyListingPushTool::class, ['product_id' => $this->product->id])
            ->assertOk()
            ->assertSee('7100')
            // Um rascunho nao esta a venda: diz-se, para ninguem pensar que ja esta.
            ->assertSee('rascunho');

        $activity = McpActivity::query()->where('tool', 'etsy_listing_push')->sole();
        $this->assertSame(['etsy_listing_id' => null], $activity->changes['before']);
        $this->assertSame(7100, $activity->changes['after']['etsy_listing_id']);
    }

    public function test_pushing_over_a_listing_on_sale_asks_first(): void
    {
        EtsyListing::query()->create(['product_id' => $this->product->id, 'etsy_listing_id' => 7100, 'state' => 'active']);
        $this->fakeEtsyListingWrites();

        // Um anuncio ativo muda a vista dos compradores assim que isto corre.
        $this->writer()->tool(EtsyListingPushTool::class, ['product_id' => $this->product->id])
            ->assertHasErrors()
            ->assertSee('"confirm": true');
        $this->assertSame(0, $this->writes());

        $this->writer()->tool(EtsyListingPushTool::class, ['product_id' => $this->product->id, 'confirm' => true])->assertOk();
        $this->assertGreaterThan(0, $this->writes());
    }

    public function test_what_blocks_the_listing_is_told_and_nothing_leaves(): void
    {
        Variant::query()->where('product_id', $this->product->id)->update(['stock' => 0]);
        $this->fakeEtsyListingWrites();

        $this->writer()->tool(EtsyListingPushTool::class, ['product_id' => $this->product->id])
            ->assertHasErrors()
            ->assertSee('stock');

        $this->assertSame(0, $this->writes());
    }

    public function test_a_deleted_product_is_not_pushed(): void
    {
        $this->product->delete();
        $this->fakeEtsyListingWrites();

        $this->writer()->tool(EtsyListingPushTool::class, ['product_id' => $this->product->id])
            ->assertHasErrors(['Produto não encontrado.']);
    }
}
