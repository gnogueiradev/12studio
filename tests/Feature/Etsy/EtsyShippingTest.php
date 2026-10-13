<?php

namespace Tests\Feature\Etsy;

use App\Etsy\EtsySettings;
use App\Models\EtsyListing;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Etsy -> Envios: perfis de envio com os seus destinos e upgrades, perfis de
 * processamento e politicas de devolucao, geridos daqui.
 *
 * Nada disto se copia para a base de dados — le-se e escreve-se na Etsy. O
 * que os testes guardam e o que sai para la: o endpoint, o verbo e os campos.
 */
class EtsyShippingTest extends TestCase
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

    /**
     * O unico pedido de escrita que saiu para a Etsy.
     */
    private function written(): Request
    {
        return $this->etsyRequests()->filter(fn (Request $request): bool => $request->method() !== 'GET')->sole();
    }

    private function writes(): int
    {
        return $this->etsyRequests()->filter(fn (Request $request): bool => $request->method() !== 'GET')->count();
    }

    private function listingUsing(int $profileId, string $state = 'active'): EtsyListing
    {
        return EtsyListing::query()->create([
            'product_id' => Product::factory()->create(['name' => 'Vaso Espiral'])->id,
            'etsy_listing_id' => 7001,
            'state' => $state,
            'shipping_profile_id' => $profileId,
        ]);
    }

    // ── A pagina ───────────────────────────────────────────────────────────

    public function test_the_page_shows_everything_the_shop_has(): void
    {
        $this->listingUsing(555);

        $this->actingAs($this->admin)
            ->get(route('admin.etsy.envios.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/etsy/envios')
                ->where('connected', true)
                ->where('error', null)
                ->has('profiles', 1)
                ->where('profiles.0.id', 555)
                ->where('profiles.0.title', 'CTT normal')
                ->where('profiles.0.listings', 1)
                ->where('profiles.0.activeListings', 1)
                ->where('profiles.0.destinations.0.country', 'PT')
                ->where('profiles.0.destinations.0.primaryCostCents', 450)
                ->where('profiles.0.destinations.0.secondaryCostCents', 100)
                ->where('profiles.0.upgrades.0.name', 'Expresso')
                ->where('profiles.0.upgrades.0.type', 'domestic')
                ->has('processingProfiles', 2)
                ->where('processingProfiles.1.state', 'made_to_order')
                ->has('returnPolicies', 1)
                ->where('returnPolicies.0.deadline', 14));
    }

    public function test_the_shop_configuration_is_read_once_and_then_remembered(): void
    {
        $this->actingAs($this->admin)->get(route('admin.etsy.envios.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.etsy.envios.index'))->assertOk();

        // Sao tres leituras por visita; em cache durante uns minutos, uma
        // tarde a acertar perfis nao gasta o limite diario de pedidos.
        $this->assertCount(1, $this->etsyRequests('/shipping-profiles'));
    }

    public function test_the_page_opens_and_explains_when_etsy_refuses(): void
    {
        // Deita fora as respostas boas do setUp: aqui a Etsy esta em baixo.
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake(['etsy.test/*' => Http::response(['error' => 'Service unavailable'], 503)]);

        $this->actingAs($this->admin)
            ->get(route('admin.etsy.envios.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('profiles', 0)
                ->where('error', fn (string $error): bool => str_contains($error, 'Service unavailable')));
    }

    // ── Perfis ─────────────────────────────────────────────────────────────

    public function test_a_new_profile_goes_with_its_first_destination(): void
    {
        Http::fake(['etsy.test/*' => Http::response($this->etsyShippingProfile(['shipping_profile_id' => 556]))]);

        $this->actingAs($this->admin)
            ->from(route('admin.etsy.envios.index'))
            ->post(route('admin.etsy.envios.perfis.guardar'), [
                'title' => 'CTT registado',
                'origin_country_iso' => 'pt',
                'origin_postal_code' => '4000-123',
                'primary_cost' => '4,50',
                'secondary_cost' => '1',
                'destination_country_iso' => 'PT',
                'min_delivery_days' => 1,
                'max_delivery_days' => 3,
            ])
            ->assertRedirect(route('admin.etsy.envios.index'))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        $request = $this->written();
        $this->assertSame('POST', $request->method());
        $this->assertStringEndsWith('/shops/4242/shipping-profiles', $request->url());
        $this->assertStringStartsWith('application/x-www-form-urlencoded', $request->header('Content-Type')[0]);
        $this->assertSame('CTT registado', $request['title']);
        $this->assertSame('PT', $request['origin_country_iso']);
        // Em euros com ponto: "4,50" escrito a portuguesa nao chega la assim.
        $this->assertSame('4.50', $request['primary_cost']);
        $this->assertSame('1.00', $request['secondary_cost']);
        $this->assertSame('PT', $request['destination_country_iso']);
        $this->assertSame('3', $request['max_delivery_days']);
    }

    public function test_editing_a_profile_never_sends_costs(): void
    {
        Http::fake(['etsy.test/*' => Http::response($this->etsyShippingProfile())]);

        $this->actingAs($this->admin)
            ->post(route('admin.etsy.envios.perfis.guardar'), [
                'shipping_profile_id' => 555,
                'title' => 'CTT normal (novo nome)',
                'origin_country_iso' => 'PT',
                // Um custo aqui era ignorado pela Etsy em silencio: os custos
                // vivem nos destinos. Nem sequer vai.
                'primary_cost' => '9,99',
            ])
            ->assertSessionHasNoErrors();

        $request = $this->written();
        $this->assertSame('PUT', $request->method());
        $this->assertStringEndsWith('/shops/4242/shipping-profiles/555', $request->url());
        $this->assertSame('CTT normal (novo nome)', $request['title']);
        $this->assertArrayNotHasKey('primary_cost', $request->data());
    }

    public function test_a_write_throws_away_what_was_remembered(): void
    {
        // A primeira visita deixa os perfis lembrados...
        $this->actingAs($this->admin)->get(route('admin.etsy.envios.index'));
        // ...(o fake() recomeca a contagem dos pedidos daqui)...
        Http::fake(['etsy.test/*' => Http::response($this->etsyShippingProfile())]);

        $this->actingAs($this->admin)->post(route('admin.etsy.envios.perfis.guardar'), [
            'shipping_profile_id' => 555, 'title' => 'Outro nome', 'origin_country_iso' => 'PT',
        ]);
        $this->actingAs($this->admin)->get(route('admin.etsy.envios.index'));

        // ...e depois de mexer a pagina tem de ir ver o que la ficou. Com a
        // cache por limpar, esta leitura nao acontecia.
        $this->assertCount(1, $this->etsyRequests('/shipping-profiles')->filter(fn (Request $r): bool => $r->method() === 'GET'));
    }

    public function test_a_profile_in_use_is_not_deleted(): void
    {
        $this->listingUsing(555, 'draft');

        $this->actingAs($this->admin)
            ->from(route('admin.etsy.envios.index'))
            ->delete(route('admin.etsy.envios.perfis.apagar', 555))
            ->assertRedirect(route('admin.etsy.envios.index'))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error')
            ->assertSessionHas('inertia.flash_data.toast.message', fn (string $m): bool => str_contains($m, 'Vaso Espiral'));

        $this->assertSame(0, $this->writes());
    }

    public function test_an_unused_profile_is_deleted_and_stops_being_the_default(): void
    {
        app(EtsySettings::class)->save(['default_shipping_profile_id' => 555]);
        Http::fake(['etsy.test/*' => Http::response('', 204)]);

        $this->actingAs($this->admin)
            ->delete(route('admin.etsy.envios.perfis.apagar', 555))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        $request = $this->written();
        $this->assertSame('DELETE', $request->method());
        $this->assertStringEndsWith('/shops/4242/shipping-profiles/555', $request->url());
        $this->assertNull(app(EtsySettings::class)->defaultShippingProfileId());
    }

    // ── Destinos e upgrades ────────────────────────────────────────────────

    public function test_costs_are_changed_on_the_destination(): void
    {
        Http::fake(['etsy.test/*' => Http::response(['shipping_profile_destination_id' => 601])]);

        $this->actingAs($this->admin)
            ->post(route('admin.etsy.envios.destinos.guardar', 555), [
                'destination_id' => 601,
                'destination_country_iso' => 'PT',
                'primary_cost' => '5,20',
                'secondary_cost' => '0',
                'min_delivery_days' => 2,
                'max_delivery_days' => 4,
            ])
            ->assertSessionHasNoErrors();

        $request = $this->written();
        $this->assertSame('PUT', $request->method());
        $this->assertStringEndsWith('/shops/4242/shipping-profiles/555/destinations/601', $request->url());
        $this->assertSame('5.20', $request['primary_cost']);
        $this->assertSame('0.00', $request['secondary_cost']);
    }

    public function test_a_new_destination_can_be_a_region(): void
    {
        Http::fake(['etsy.test/*' => Http::response(['shipping_profile_destination_id' => 602])]);

        $this->actingAs($this->admin)
            ->post(route('admin.etsy.envios.destinos.guardar', 555), [
                'destination_region' => 'eu',
                'primary_cost' => '9',
                'secondary_cost' => '2,5',
                'min_delivery_days' => 3,
                'max_delivery_days' => 7,
            ])
            ->assertSessionHasNoErrors();

        $request = $this->written();
        $this->assertSame('POST', $request->method());
        $this->assertStringEndsWith('/shipping-profiles/555/destinations', $request->url());
        $this->assertSame('eu', $request['destination_region']);
        $this->assertArrayNotHasKey('destination_country_iso', $request->data());
    }

    public function test_a_destination_is_a_country_or_a_region_and_needs_a_delivery_time(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.etsy.envios.destinos.guardar', 555), [
                'destination_country_iso' => 'PT',
                'destination_region' => 'eu',
                'primary_cost' => '5',
                'secondary_cost' => '0',
            ])
            ->assertSessionHasErrors(['destination_region', 'min_delivery_days']);

        $this->assertSame(0, $this->writes());
    }

    public function test_an_upgrade_says_whether_it_is_domestic_or_international(): void
    {
        Http::fake(['etsy.test/*' => Http::response(['upgrade_id' => 702])]);

        $this->actingAs($this->admin)
            ->post(route('admin.etsy.envios.upgrades.guardar', 555), [
                'upgrade_name' => 'Expresso internacional',
                'type' => 'international',
                'price' => '12',
                'secondary_price' => '3',
                'min_delivery_days' => 2,
                'max_delivery_days' => 5,
            ])
            ->assertSessionHasNoErrors();

        $request = $this->written();
        $this->assertStringEndsWith('/shipping-profiles/555/upgrades', $request->url());
        // A Etsy quer 0 (nacional) ou 1 (internacional).
        $this->assertSame('1', $request['type']);
        $this->assertSame('12.00', $request['price']);
    }

    public function test_destinations_and_upgrades_are_deleted_under_their_profile(): void
    {
        Http::fake(['etsy.test/*' => Http::response('', 204)]);

        $this->actingAs($this->admin)->delete(route('admin.etsy.envios.destinos.apagar', [555, 601]));
        $this->actingAs($this->admin)->delete(route('admin.etsy.envios.upgrades.apagar', [555, 701]));

        $urls = $this->etsyRequests()->filter(fn (Request $r): bool => $r->method() === 'DELETE')->map->url()->all();
        $this->assertStringEndsWith('/shipping-profiles/555/destinations/601', $urls[0]);
        $this->assertStringEndsWith('/shipping-profiles/555/upgrades/701', $urls[1]);
    }

    // ── Processamento ──────────────────────────────────────────────────────

    public function test_a_processing_profile_is_created_and_edited(): void
    {
        Http::fake(['etsy.test/*' => Http::response(['readiness_state_id' => 803])]);

        $this->actingAs($this->admin)
            ->post(route('admin.etsy.envios.processamento.guardar'), [
                'readiness_state' => 'made_to_order',
                'min_processing_time' => 1,
                'max_processing_time' => 2,
                'processing_time_unit' => 'weeks',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('admin.etsy.envios.processamento.guardar'), [
                'definition_id' => 802,
                'readiness_state' => 'made_to_order',
                'min_processing_time' => 4,
                'max_processing_time' => 6,
                'processing_time_unit' => 'days',
            ])
            ->assertSessionHasNoErrors();

        [$created, $updated] = $this->etsyRequests()->filter(fn (Request $r): bool => $r->method() !== 'GET')->values()->all();

        $this->assertSame('POST', $created->method());
        $this->assertStringEndsWith('/shops/4242/readiness-state-definitions', $created->url());
        $this->assertSame('weeks', $created['processing_time_unit']);

        $this->assertSame('PUT', $updated->method());
        $this->assertStringEndsWith('/readiness-state-definitions/802', $updated->url());
        $this->assertSame('6', $updated['max_processing_time']);
    }

    public function test_the_processing_profile_everything_defaults_to_is_not_deleted(): void
    {
        app(EtsySettings::class)->save(['made_to_order_profile_id' => 802]);

        $this->actingAs($this->admin)
            ->delete(route('admin.etsy.envios.processamento.apagar', 802))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');

        $this->assertSame(0, $this->writes());
    }

    // ── Atribuir ───────────────────────────────────────────────────────────

    public function test_applying_a_profile_updates_etsy_for_listings_already_there(): void
    {
        $onEtsy = $this->listingUsing(111, 'draft');
        $notYet = Product::factory()->create();
        Http::fake(['etsy.test/*' => Http::response($this->etsyListing(['shipping_profile_id' => 555]))]);

        $this->actingAs($this->admin)
            ->from(route('admin.etsy.envios.index'))
            ->post(route('admin.etsy.envios.perfis.aplicar', 555), ['product_ids' => [$onEtsy->product_id, $notYet->id]])
            ->assertRedirect(route('admin.etsy.envios.index'))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        // O anuncio que ja existe la muda la; o produto que ainda nao tem
        // anuncio fica so com o perfil escolhido, para quando for enviado.
        $request = $this->written();
        $this->assertSame('PATCH', $request->method());
        $this->assertStringEndsWith('/shops/4242/listings/7001', $request->url());
        $this->assertSame('555', $request['shipping_profile_id']);

        $this->assertSame(555, $onEtsy->refresh()->shipping_profile_id);
        $prepared = EtsyListing::query()->where('product_id', $notYet->id)->sole();
        $this->assertSame(555, $prepared->shipping_profile_id);
        $this->assertNull($prepared->etsy_listing_id);
    }

    public function test_a_profile_that_etsy_refuses_for_a_listing_is_not_recorded_here(): void
    {
        $listing = $this->listingUsing(111, 'draft');
        Http::fake(['etsy.test/*' => Http::response(['error' => 'Invalid shipping profile'], 400)]);

        $this->actingAs($this->admin)
            ->post(route('admin.etsy.envios.perfis.aplicar', 555), ['product_ids' => [$listing->product_id]])
            ->assertSessionHas('inertia.flash_data.toast.type', 'error')
            ->assertSessionHas('inertia.flash_data.toast.message', fn (string $m): bool => str_contains($m, 'Invalid shipping profile'));

        // O 12studio nao pode ficar a dizer uma coisa e a Etsy outra.
        $this->assertSame(111, $listing->refresh()->shipping_profile_id);
    }

    // ── Predefinicoes ──────────────────────────────────────────────────────

    public function test_the_defaults_are_kept_here(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.etsy.envios.index'))
            ->patch(route('admin.etsy.envios.predefinicoes'), [
                'default_shipping_profile_id' => 555,
                'ready_to_ship_profile_id' => 801,
                'made_to_order_profile_id' => 802,
                'default_return_policy_id' => null,
            ])
            ->assertRedirect(route('admin.etsy.envios.index'));

        $settings = app(EtsySettings::class);
        $this->assertSame(555, $settings->defaultShippingProfileId());
        $this->assertSame(801, $settings->readyToShipProfileId());
        $this->assertSame(802, $settings->madeToOrderProfileId());
        $this->assertNull($settings->defaultReturnPolicyId());

        // E coisa nossa: escolher predefinicoes nao escreve na Etsy.
        $this->assertSame(0, $this->writes());
    }

    // ── Quem entra, e o que a Etsy recusa ──────────────────────────────────

    public function test_only_admins_touch_the_shipping_configuration(): void
    {
        foreach ([User::factory()->create(), User::factory()->production()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.etsy.envios.index'))->assertForbidden();
            $this->actingAs($user)
                ->post(route('admin.etsy.envios.perfis.guardar'), ['title' => 'X', 'origin_country_iso' => 'PT'])
                ->assertForbidden();
            $this->actingAs($user)->delete(route('admin.etsy.envios.perfis.apagar', 555))->assertForbidden();
        }

        $this->assertSame(0, $this->writes());
    }

    public function test_what_etsy_refuses_reaches_the_screen_in_its_own_words(): void
    {
        Http::fake(['etsy.test/*' => Http::response(['error' => 'Title is too long'], 400)]);

        $this->actingAs($this->admin)
            ->from(route('admin.etsy.envios.index'))
            ->post(route('admin.etsy.envios.perfis.guardar'), [
                'shipping_profile_id' => 555, 'title' => 'Nome', 'origin_country_iso' => 'PT',
            ])
            ->assertRedirect(route('admin.etsy.envios.index'))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error')
            ->assertSessionHas('inertia.flash_data.toast.message', fn (string $m): bool => str_contains($m, 'Title is too long'));
    }
}
