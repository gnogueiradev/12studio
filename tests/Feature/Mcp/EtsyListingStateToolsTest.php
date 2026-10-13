<?php

namespace Tests\Feature\Mcp;

use App\Jobs\SyncEtsyListing;
use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Etsy\EtsyListingDeactivateTool;
use App\Mcp\Tools\Etsy\EtsyListingDeleteTool;
use App\Mcp\Tools\Etsy\EtsyListingPreviewTool;
use App\Mcp\Tools\Etsy\EtsyListingPublishTool;
use App\Mcp\Tools\Etsy\EtsyListingSaveTool;
use App\Mcp\Tools\Write\ProductDeleteTool;
use App\Models\EtsyListing;
use App\Models\McpActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Passport\Passport;
use Tests\Feature\Etsy\FakesEtsy;
use Tests\TestCase;

/**
 * Fase 3 no MCP: publicar, desativar e apagar um anuncio. As tres pedem
 * sempre confirmacao — uma custa dinheiro, as outras tiram o produto da
 * frente dos compradores.
 */
class EtsyListingStateToolsTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->configureEtsy();
        $this->connectEtsy();
        $this->fakeEtsyShopConfig();

        Queue::fake([SyncEtsyListing::class]);

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

    // ── etsy_listing_publish ───────────────────────────────────────────────

    public function test_publishing_always_asks_first_and_says_it_costs(): void
    {
        $product = $this->etsyPushedProduct('draft');

        $this->reader()->tool(EtsyListingPublishTool::class, ['product_id' => $product->id])->assertNotRegistered();

        $this->writer()->tool(EtsyListingPublishTool::class, ['product_id' => $product->id])
            ->assertHasErrors()
            ->assertSee('taxa')
            ->assertSee('"confirm": true');
        $this->assertSame([], $this->writes());

        $this->writer()->tool(EtsyListingPublishTool::class, ['product_id' => $product->id, 'confirm' => true])
            ->assertOk()
            ->assertSee('à venda');

        $this->assertSame('active', EtsyListing::query()->sole()->state);

        $activity = McpActivity::query()->where('tool', 'etsy_listing_publish')->latest('id')->first();
        $this->assertSame(['state' => 'draft'], $activity->changes['before']);
        $this->assertSame(['state' => 'active'], $activity->changes['after']);
    }

    public function test_what_stops_a_publication_is_told_before_the_question(): void
    {
        $product = $this->etsyPushedProduct('draft');
        $product->update(['name' => 'Vaso Espiral XL']);

        // Nao vale a pena pedir confirmacao para uma coisa que nao pode ir.
        $this->writer()->tool(EtsyListingPublishTool::class, ['product_id' => $product->id])
            ->assertHasErrors()
            ->assertSee('por enviar')
            ->assertDontSee('"confirm": true');
    }

    // ── etsy_listing_deactivate ────────────────────────────────────────────

    public function test_deactivating_asks_first(): void
    {
        $product = $this->etsyPushedProduct('active');

        $this->writer()->tool(EtsyListingDeactivateTool::class, ['product_id' => $product->id])
            ->assertHasErrors()
            ->assertSee('"confirm": true');
        $this->assertSame('active', EtsyListing::query()->sole()->state);

        $this->writer()->tool(EtsyListingDeactivateTool::class, ['product_id' => $product->id, 'confirm' => true])->assertOk();

        $this->assertSame('inactive', EtsyListing::query()->sole()->state);
    }

    // ── etsy_listing_delete ────────────────────────────────────────────────

    public function test_deleting_asks_first_and_says_it_cannot_be_undone(): void
    {
        $product = $this->etsyPushedProduct('active');

        $this->writer()->tool(EtsyListingDeleteTool::class, ['product_id' => $product->id])
            ->assertHasErrors()
            ->assertSee('7100')
            ->assertSee('não se recupera')
            ->assertSee('"confirm": true');
        $this->assertSame([], $this->writes());

        $this->writer()->tool(EtsyListingDeleteTool::class, ['product_id' => $product->id, 'confirm' => true])->assertOk();

        $this->assertSame(['DELETE /listings/7100'], $this->writes());
        $this->assertNull(EtsyListing::query()->sole()->etsy_listing_id);

        $activity = McpActivity::query()->where('tool', 'etsy_listing_delete')->latest('id')->first();
        $this->assertSame(['etsy_listing_id' => 7100], $activity->changes['before']);
        $this->assertSame(['etsy_listing_id' => null], $activity->changes['after']);
    }

    public function test_the_listing_of_a_deleted_product_can_be_deleted_by_hand(): void
    {
        $product = $this->etsyPushedProduct('active');
        $product->deleteQuietly();

        $this->writer()->tool(EtsyListingDeleteTool::class, ['product_id' => $product->id, 'confirm' => true])->assertOk();

        $this->assertNull(EtsyListing::query()->sole()->etsy_listing_id);
    }

    public function test_there_is_nothing_to_delete_when_the_product_is_not_on_etsy(): void
    {
        $product = $this->etsyReadyProduct();

        $this->writer()->tool(EtsyListingDeleteTool::class, ['product_id' => $product->id, 'confirm' => true])
            ->assertHasErrors()
            ->assertSee('não tem anúncio');
    }

    // ── product_delete ─────────────────────────────────────────────────────

    public function test_deleting_a_product_warns_about_its_listing_and_takes_it_too(): void
    {
        $product = $this->etsyPushedProduct('active');

        $this->writer()->tool(ProductDeleteTool::class, ['id' => $product->id])
            ->assertHasErrors()
            ->assertSee('Etsy')
            ->assertSee('7100');

        $this->writer()->tool(ProductDeleteTool::class, ['id' => $product->id, 'confirm' => true])
            ->assertOk()
            ->assertSee('Etsy');

        $this->assertContains('DELETE /listings/7100', $this->writes());
    }

    // ── Sincronizacao ──────────────────────────────────────────────────────

    public function test_the_sync_of_a_listing_can_be_switched_off_and_seen(): void
    {
        $product = $this->etsyPushedProduct('active');

        $this->writer()->tool(EtsyListingSaveTool::class, ['product_id' => $product->id, 'sync_enabled' => false])->assertOk();

        $this->assertFalse(EtsyListing::query()->sole()->sync_enabled);

        $this->reader()->tool(EtsyListingPreviewTool::class, ['product_id' => $product->id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('sync_enabled', false)
                ->where('state', 'active')
                ->etc());
    }
}
