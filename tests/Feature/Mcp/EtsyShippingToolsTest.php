<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Etsy\EtsyDeleteTool;
use App\Mcp\Tools\Etsy\EtsyProcessingProfileSaveTool;
use App\Mcp\Tools\Etsy\EtsyReturnPolicySaveTool;
use App\Mcp\Tools\Etsy\EtsySectionSaveTool;
use App\Mcp\Tools\Etsy\EtsySectionsListTool;
use App\Mcp\Tools\Etsy\EtsyShippingAssignTool;
use App\Mcp\Tools\Etsy\EtsyShippingDestinationSaveTool;
use App\Mcp\Tools\Etsy\EtsyShippingListTool;
use App\Mcp\Tools\Etsy\EtsyShippingProfileSaveTool;
use App\Mcp\Tools\Etsy\EtsyShippingUpgradeSaveTool;
use App\Mcp\Tools\Etsy\EtsyShopUpdateTool;
use App\Models\EtsyListing;
use App\Models\McpActivity;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Etsy\FakesEtsy;
use Tests\TestCase;

/**
 * Fase 1B no MCP: os envios e a loja, geridos pelo Claude.
 *
 * A regra que atravessa tudo: criar e livre; mexer no que os compradores
 * estao a ver (custos de um perfil com anuncios ativos, textos da loja) e
 * apagar seja o que for pedem "confirm": true.
 */
class EtsyShippingToolsTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureEtsy();
        $this->connectEtsy();
        $this->fakeEtsyShopConfig();

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

    private function writes(): int
    {
        return $this->etsyRequests()->filter(fn (Request $request): bool => $request->method() !== 'GET')->count();
    }

    private function written(): Request
    {
        return $this->etsyRequests()->filter(fn (Request $request): bool => $request->method() !== 'GET')->sole();
    }

    private function listingUsing(array $attributes, string $state = 'active'): EtsyListing
    {
        return EtsyListing::query()->create([
            'product_id' => Product::factory()->create(['name' => 'Vaso Espiral'])->id,
            'etsy_listing_id' => 7001,
            'state' => $state,
            ...$attributes,
        ]);
    }

    // ── Ler ────────────────────────────────────────────────────────────────

    public function test_the_shipping_overview_has_profiles_processing_returns_and_carriers(): void
    {
        $this->listingUsing(['shipping_profile_id' => 555]);

        $this->reader()->tool(EtsyShippingListTool::class)
            ->assertOk()
            ->assertSee('CTT normal')
            ->assertSee('4,50')
            ->assertSee('Expresso')
            ->assertSee('made_to_order')
            ->assertSee('ctt_normal')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('shipping_profiles.0.shipping_profile_id', 555)
                ->where('shipping_profiles.0.listings', 1)
                ->where('shipping_profiles.0.active_listings', 1)
                ->where('shipping_profiles.0.destinations.0.primary_cost.cents', 450)
                ->where('shipping_profiles.0.upgrades.0.type', 'domestic')
                ->where('processing_profiles.1.readiness_state_id', 802)
                ->where('return_policies.0.return_policy_id', 901)
                ->etc());
    }

    public function test_the_sections_come_with_the_category_map(): void
    {
        $this->reader()->tool(EtsySectionsListTool::class)
            ->assertOk()
            ->assertSee('Vasos')
            ->assertSee('Suportes');
    }

    public function test_a_read_only_key_sees_none_of_the_write_tools(): void
    {
        foreach ([
            EtsyShippingProfileSaveTool::class, EtsyShippingDestinationSaveTool::class,
            EtsyShippingUpgradeSaveTool::class, EtsyProcessingProfileSaveTool::class,
            EtsyReturnPolicySaveTool::class, EtsyShippingAssignTool::class,
            EtsySectionSaveTool::class, EtsyShopUpdateTool::class, EtsyDeleteTool::class,
        ] as $tool) {
            $this->reader()->tool($tool, [])->assertNotRegistered();
        }
    }

    // ── Perfis ─────────────────────────────────────────────────────────────

    public function test_creating_a_profile_is_free_and_leaves_a_trail(): void
    {
        Http::fake(['etsy.test/*' => Http::response($this->etsyShippingProfile(['shipping_profile_id' => 556, 'title' => 'CTT registado']))]);

        $this->writer()->tool(EtsyShippingProfileSaveTool::class, [
            'title' => 'CTT registado',
            'origin_country_iso' => 'PT',
            'primary_cost' => '4,50',
            'secondary_cost' => '1',
            'destination_country_iso' => 'PT',
            'min_delivery_days' => 1,
            'max_delivery_days' => 3,
        ])->assertOk()->assertSee('556');

        $this->assertSame('4.50', $this->written()['primary_cost']);

        $activity = McpActivity::query()->where('tool', 'etsy_shipping_profile_save')->sole();
        $this->assertSame(556, $activity->changes['shipping_profile_id']);
    }

    public function test_a_new_profile_without_a_first_destination_is_refused_before_etsy(): void
    {
        $this->writer()->tool(EtsyShippingProfileSaveTool::class, ['title' => 'Sem destino', 'origin_country_iso' => 'PT'])
            ->assertHasErrors();

        $this->assertSame(0, $this->writes());
    }

    // ── Destinos: a regra da confirmacao ───────────────────────────────────

    public function test_changing_costs_under_active_listings_asks_first(): void
    {
        $this->listingUsing(['shipping_profile_id' => 555]);
        Http::fake(['etsy.test/*' => Http::response(['shipping_profile_destination_id' => 601])]);

        $arguments = [
            'shipping_profile_id' => 555, 'destination_id' => 601, 'destination_country_iso' => 'PT',
            'primary_cost' => '6', 'secondary_cost' => '1', 'min_delivery_days' => 1, 'max_delivery_days' => 3,
        ];

        // Muda o que os compradores pagam de portes, ja: primeiro mostra-se.
        $this->writer()->tool(EtsyShippingDestinationSaveTool::class, $arguments)
            ->assertHasErrors()
            ->assertSee('1 anúncio ativo')
            ->assertSee('"confirm": true');
        $this->assertSame(0, $this->writes());

        $this->writer()->tool(EtsyShippingDestinationSaveTool::class, [...$arguments, 'confirm' => true])->assertOk();

        $request = $this->written();
        $this->assertStringEndsWith('/shipping-profiles/555/destinations/601', $request->url());
        $this->assertSame('6.00', $request['primary_cost']);
        $this->assertArrayNotHasKey('confirm', $request->data());
    }

    public function test_a_profile_nobody_is_buying_from_changes_without_asking(): void
    {
        $this->listingUsing(['shipping_profile_id' => 555], 'draft');
        Http::fake(['etsy.test/*' => Http::response(['shipping_profile_destination_id' => 602])]);

        $this->writer()->tool(EtsyShippingDestinationSaveTool::class, [
            'shipping_profile_id' => 555, 'destination_region' => 'eu',
            'primary_cost' => '9', 'secondary_cost' => '2', 'min_delivery_days' => 3, 'max_delivery_days' => 7,
        ])->assertOk();

        $this->assertSame(1, $this->writes());
    }

    public function test_an_upgrade_follows_the_same_rule(): void
    {
        $this->listingUsing(['shipping_profile_id' => 555]);
        Http::fake(['etsy.test/*' => Http::response(['upgrade_id' => 702])]);

        $arguments = [
            'shipping_profile_id' => 555, 'upgrade_name' => 'Expresso', 'type' => 'domestic',
            'price' => '3', 'secondary_price' => '0',
        ];

        $this->writer()->tool(EtsyShippingUpgradeSaveTool::class, $arguments)->assertHasErrors();
        $this->assertSame(0, $this->writes());

        $this->writer()->tool(EtsyShippingUpgradeSaveTool::class, [...$arguments, 'confirm' => true])->assertOk();
        $this->assertSame('0', $this->written()['type']);
    }

    // ── Processamento e devolucoes ─────────────────────────────────────────

    public function test_a_new_processing_profile_is_free_and_editing_one_in_use_asks(): void
    {
        $this->listingUsing(['readiness_state_id' => 802]);
        Http::fake(['etsy.test/*' => Http::response(['readiness_state_id' => 803])]);

        $this->writer()->tool(EtsyProcessingProfileSaveTool::class, [
            'readiness_state' => 'made_to_order', 'min_processing_time' => 1, 'max_processing_time' => 2, 'processing_time_unit' => 'weeks',
        ])->assertOk();

        // Mexer no prazo de um perfil em uso muda a data de envio que os
        // compradores veem nos anuncios ativos.
        $this->writer()->tool(EtsyProcessingProfileSaveTool::class, [
            'definition_id' => 802, 'readiness_state' => 'made_to_order',
            'min_processing_time' => 5, 'max_processing_time' => 9, 'processing_time_unit' => 'days',
        ])->assertHasErrors()->assertSee('"confirm": true');

        $this->assertSame(1, $this->writes());
    }

    public function test_a_return_policy_in_use_by_active_listings_asks_too(): void
    {
        $this->listingUsing(['return_policy_id' => 901]);
        Http::fake(['etsy.test/*' => Http::response(['return_policy_id' => 901])]);

        $arguments = ['policy_id' => 901, 'accepts_returns' => true, 'accepts_exchanges' => true, 'return_deadline' => 30];

        $this->writer()->tool(EtsyReturnPolicySaveTool::class, $arguments)->assertHasErrors();
        $this->assertSame(0, $this->writes());

        $this->writer()->tool(EtsyReturnPolicySaveTool::class, [...$arguments, 'confirm' => true])->assertOk();
        $this->assertStringEndsWith('/policies/return/901', $this->written()->url());
    }

    // ── Atribuir ───────────────────────────────────────────────────────────

    public function test_assigning_a_profile_to_an_active_listing_asks_and_then_updates_etsy(): void
    {
        $listing = $this->listingUsing(['shipping_profile_id' => 111]);
        Http::fake(['etsy.test/*' => Http::response($this->etsyListing())]);

        $arguments = ['shipping_profile_id' => 555, 'product_ids' => [$listing->product_id]];

        $this->writer()->tool(EtsyShippingAssignTool::class, $arguments)->assertHasErrors()->assertSee('Vaso Espiral');
        $this->assertSame(111, $listing->refresh()->shipping_profile_id);

        $this->writer()->tool(EtsyShippingAssignTool::class, [...$arguments, 'confirm' => true])->assertOk();

        $request = $this->written();
        $this->assertSame('PATCH', $request->method());
        $this->assertStringEndsWith('/shops/4242/listings/7001', $request->url());
        $this->assertSame(555, $listing->refresh()->shipping_profile_id);

        $activity = McpActivity::query()->where('tool', 'etsy_shipping_assign')->where('result', McpActivity::RESULT_OK)->sole();
        $this->assertSame(555, $activity->changes['shipping_profile_id']);
    }

    public function test_a_profile_that_does_not_exist_is_not_assigned(): void
    {
        $product = Product::factory()->create();

        $this->writer()->tool(EtsyShippingAssignTool::class, ['shipping_profile_id' => 999, 'product_ids' => [$product->id]])
            ->assertHasErrors()
            ->assertSee('etsy_shipping_list');

        $this->assertSame(0, EtsyListing::query()->count());
    }

    // ── Loja ───────────────────────────────────────────────────────────────

    public function test_sections_are_saved_without_asking(): void
    {
        Http::fake(['etsy.test/*' => Http::response(['shop_section_id' => 33, 'title' => 'Candeeiros'])]);

        $this->writer()->tool(EtsySectionSaveTool::class, ['title' => 'Candeeiros'])->assertOk()->assertSee('33');

        $this->assertStringEndsWith('/shops/4242/sections', $this->written()->url());
    }

    public function test_the_public_texts_of_the_shop_always_ask(): void
    {
        Http::fake(['etsy.test/*' => Http::response(['shop_id' => 4242])]);

        // E a montra: o que aqui se escreve fica a vista de toda a gente.
        $this->writer()->tool(EtsyShopUpdateTool::class, ['announcement' => 'Fechados em agosto.'])
            ->assertHasErrors()
            ->assertSee('Bem-vindos!')
            ->assertSee('Fechados em agosto.');
        $this->assertSame(0, $this->writes());

        $this->writer()->tool(EtsyShopUpdateTool::class, ['announcement' => 'Fechados em agosto.', 'confirm' => true])->assertOk();

        $request = $this->written();
        $this->assertSame('PUT', $request->method());
        $this->assertSame('Fechados em agosto.', $request['announcement']);
        // So o que se pediu para mudar: o titulo fica como esta.
        $this->assertArrayNotHasKey('title', $request->data());

        $activity = McpActivity::query()->where('tool', 'etsy_shop_update')->where('result', McpActivity::RESULT_OK)->sole();
        $this->assertSame(['announcement' => 'Bem-vindos!'], $activity->changes['before']);
        $this->assertSame(['announcement' => 'Fechados em agosto.'], $activity->changes['after']);
    }

    // ── Apagar ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function deletions(): array
    {
        return [
            'perfil de envio' => [['kind' => 'shipping_profile', 'id' => 555], '/shops/4242/shipping-profiles/555'],
            'destino' => [['kind' => 'shipping_destination', 'id' => 601, 'shipping_profile_id' => 555], '/shipping-profiles/555/destinations/601'],
            'upgrade' => [['kind' => 'shipping_upgrade', 'id' => 701, 'shipping_profile_id' => 555], '/shipping-profiles/555/upgrades/701'],
            'perfil de processamento' => [['kind' => 'processing_profile', 'id' => 801], '/readiness-state-definitions/801'],
            'politica de devolucao' => [['kind' => 'return_policy', 'id' => 901], '/policies/return/901'],
            'seccao' => [['kind' => 'section', 'id' => 32], '/shops/4242/sections/32'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('deletions')]
    public function test_deleting_always_asks_and_then_hits_the_right_endpoint(array $arguments, string $endpoint): void
    {
        Http::fake(['etsy.test/*' => Http::response('', 204)]);

        $this->writer()->tool(EtsyDeleteTool::class, $arguments)->assertHasErrors()->assertSee('"confirm": true');
        $this->assertSame(0, $this->writes());

        $this->writer()->tool(EtsyDeleteTool::class, [...$arguments, 'confirm' => true])->assertOk();

        $request = $this->written();
        $this->assertSame('DELETE', $request->method());
        $this->assertStringEndsWith($endpoint, $request->url());
    }

    public function test_deleting_what_is_in_use_is_refused_even_when_confirmed(): void
    {
        $this->listingUsing(['shipping_profile_id' => 555], 'draft');

        $this->writer()->tool(EtsyDeleteTool::class, ['kind' => 'shipping_profile', 'id' => 555, 'confirm' => true])
            ->assertHasErrors()
            ->assertSee('Vaso Espiral');

        $this->assertSame(0, $this->writes());
    }

    public function test_a_destination_needs_to_know_its_profile(): void
    {
        $this->writer()->tool(EtsyDeleteTool::class, ['kind' => 'shipping_destination', 'id' => 601, 'confirm' => true])
            ->assertHasErrors();

        $this->assertSame(0, $this->writes());
    }

    public function test_what_etsy_refuses_comes_back_in_its_own_words(): void
    {
        Http::fake(['etsy.test/*' => Http::response(['error' => 'Section title already exists'], 409)]);

        $this->writer()->tool(EtsySectionSaveTool::class, ['title' => 'Vasos'])
            ->assertHasErrors()
            ->assertSee('Section title already exists');
    }
}
