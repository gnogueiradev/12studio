<?php

namespace Tests\Feature\Etsy;

use App\Etsy\EtsyException;
use App\Etsy\EtsyListingSync;
use App\Etsy\EtsyTokens;
use App\Models\EtsyListing;
use App\Models\EtsyVariant;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A loja ja tem anuncios feitos a mao. Ligar e dizer "este anuncio e este
 * produto" — sem duplicar, e sem reescrever o que la esta.
 */
class EtsyListingLinkTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureEtsy();
        $this->connectEtsy();

        $this->admin = User::factory()->admin()->create();
        $this->product = Product::factory()->create(['name' => 'Vaso Espiral']);
    }

    private function sync(): EtsyListingSync
    {
        return app(EtsyListingSync::class);
    }

    private function fakeListing(array $overrides = []): void
    {
        Http::fake(['etsy.test/v3/application/listings/7001*' => Http::response($this->etsyListing($overrides))]);
    }

    // ── Ligar ──────────────────────────────────────────────────────────────

    public function test_linking_adopts_what_the_listing_already_says(): void
    {
        $this->fakeListing();

        $listing = $this->sync()->link($this->product, 7001);

        // O texto escrito a mao na Etsy passa a ser o conteudo Etsy do
        // produto: o primeiro envio nao o pode apagar com o texto do site.
        $this->assertSame(7001, $listing->etsy_listing_id);
        $this->assertSame('draft', $listing->state);
        $this->assertSame('Spiral Vase — 3D printed', $listing->title);
        $this->assertSame("A vase printed in PLA.\nMade to order.", $listing->description);
        $this->assertSame(['vase', '3d printed'], $listing->tags);
        $this->assertSame(['PLA'], $listing->materials);
        $this->assertSame(1234, $listing->taxonomy_id);
        $this->assertSame('i_did', $listing->who_made);
        $this->assertSame('made_to_order', $listing->when_made);
        $this->assertSame(555, $listing->shipping_profile_id);
        $this->assertSame(31, $listing->shop_section_id);
        $this->assertSame($this->product->id, $listing->product_id);
    }

    public function test_linking_writes_nothing_to_etsy(): void
    {
        $this->fakeListing();

        $this->sync()->link($this->product, 7001);

        $this->assertNotEmpty($this->etsyRequests());
        $this->assertTrue($this->etsyRequests()->every(fn ($request): bool => $request->method() === 'GET'));
    }

    public function test_variants_are_matched_to_the_listing_by_sku(): void
    {
        $black = Variant::factory()->for($this->product)->create(['sku' => 'VASO-1']);
        $white = Variant::factory()->for($this->product)->create(['sku' => 'vaso-2']);
        $onlyHere = Variant::factory()->for($this->product)->create(['sku' => 'VASO-9']);
        $this->fakeListing();

        $this->sync()->link($this->product, 7001);

        $this->assertSame(9101, EtsyVariant::query()->where('variant_id', $black->id)->sole()->etsy_product_id);
        // Sem olhar a maiusculas: a Etsy guarda o SKU como foi escrito la.
        $this->assertSame(9202, EtsyVariant::query()->where('variant_id', $white->id)->sole()->etsy_offering_id);
        $this->assertSame(0, EtsyVariant::query()->where('variant_id', $onlyHere->id)->count());
    }

    public function test_the_prices_on_etsy_are_not_adopted(): void
    {
        $variant = Variant::factory()->for($this->product)->create(['sku' => 'VASO-1', 'price_cents' => 1990]);
        $this->fakeListing();

        $this->sync()->link($this->product, 7001);

        // O preco e do 12studio. O que la esta aparece como diferenca na
        // pre-visualizacao, nao como preco proprio adotado em silencio.
        $this->assertNull(EtsyVariant::query()->where('variant_id', $variant->id)->sole()->etsy_price_cents);
    }

    public function test_the_photos_already_on_the_listing_are_remembered(): void
    {
        $this->fakeListing();

        $listing = $this->sync()->link($this->product, 7001);

        $this->assertSame([81, 82], $listing->images()->orderBy('rank')->pluck('etsy_image_id')->all());
        $this->assertTrue($listing->images->every(fn ($image): bool => $image->product_image_id === null));
    }

    public function test_a_listing_from_another_shop_is_refused(): void
    {
        $this->fakeListing(['shop_id' => 1]);

        try {
            $this->sync()->link($this->product, 7001);
            $this->fail('Devia ter lancado.');
        } catch (EtsyException $exception) {
            $this->assertStringContainsString('não é da loja', $exception->getMessage());
        }

        $this->assertSame(0, EtsyListing::query()->count());
    }

    public function test_a_listing_cannot_belong_to_two_products(): void
    {
        $this->fakeListing();
        $this->sync()->link($this->product, 7001);

        try {
            $this->sync()->link(Product::factory()->create(['name' => 'Outro']), 7001);
            $this->fail('Devia ter lancado.');
        } catch (EtsyException $exception) {
            $this->assertStringContainsString('Vaso Espiral', $exception->getMessage());
        }

        $this->assertSame(1, EtsyListing::query()->count());
    }

    public function test_linking_again_replaces_the_previous_link(): void
    {
        $variant = Variant::factory()->for($this->product)->create(['sku' => 'VASO-1']);
        $this->fakeListing();
        $this->sync()->link($this->product, 7001);

        Http::fake(['etsy.test/v3/application/listings/7002*' => Http::response($this->etsyListing([
            'listing_id' => 7002,
            'title' => 'Outro anúncio',
            'skus' => [],
            'inventory' => ['products' => []],
            'images' => [],
        ]))]);

        $listing = $this->sync()->link($this->product, 7002);

        $this->assertSame(1, EtsyListing::query()->count());
        $this->assertSame(7002, $listing->etsy_listing_id);
        $this->assertSame('Outro anúncio', $listing->title);
        // O que era do anuncio antigo nao fica pendurado no novo.
        $this->assertSame(0, EtsyVariant::query()->where('variant_id', $variant->id)->count());
        $this->assertSame(0, $listing->images()->count());
    }

    public function test_unlinking_forgets_the_link_and_leaves_etsy_alone(): void
    {
        Variant::factory()->for($this->product)->create(['sku' => 'VASO-1']);
        $this->fakeListing();
        $this->sync()->link($this->product, 7001);
        $before = $this->etsyRequests()->count();

        $this->sync()->unlink($this->product);

        $this->assertSame(0, EtsyListing::query()->count());
        $this->assertSame(0, EtsyVariant::query()->count());
        $this->assertCount($before, $this->etsyRequests());
    }

    // ── O ecra ─────────────────────────────────────────────────────────────

    public function test_the_page_lists_the_shop_listings_with_who_they_belong_to(): void
    {
        Variant::factory()->for($this->product)->create(['sku' => 'VASO-1']);
        $linked = Product::factory()->create(['name' => 'Suporte']);
        EtsyListing::query()->create(['product_id' => $linked->id, 'etsy_listing_id' => 7002, 'state' => 'draft']);

        Http::fake(['etsy.test/v3/application/shops/4242/listings*' => Http::response(['count' => 2, 'results' => [
            $this->etsyListing(),
            $this->etsyListing(['listing_id' => 7002, 'title' => 'Phone stand', 'skus' => []]),
        ]])]);

        $this->actingAs($this->admin)
            ->get(route('admin.etsy.anuncios.index', ['state' => 'draft']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/etsy/anuncios')
                ->where('connected', true)
                ->has('listings', 2)
                ->where('listings.0.listingId', 7001)
                ->where('listings.0.linkedProduct', null)
                // O SKU bate com uma variante do Vaso Espiral: e a sugestao.
                ->where('listings.0.suggestedProduct.id', $this->product->id)
                ->where('listings.1.linkedProduct.name', 'Suporte')
                ->where('filters.state', 'draft'));

        $this->assertStringContainsString('state=draft', $this->etsyRequests('/shops/4242/listings')->sole()->url());
    }

    public function test_without_a_shop_the_page_opens_and_asks_to_connect(): void
    {
        $this->app->make(EtsyTokens::class)->forget();
        Http::fake();

        $this->actingAs($this->admin)
            ->get(route('admin.etsy.anuncios.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('connected', false)
                ->has('listings', 0));

        Http::assertNothingSent();
    }

    public function test_when_etsy_refuses_the_page_still_opens_and_says_why(): void
    {
        Http::fake(['etsy.test/*' => Http::response(['error' => 'Service unavailable'], 503)]);

        $this->actingAs($this->admin)
            ->get(route('admin.etsy.anuncios.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('listings', 0)
                ->where('error', fn (string $error): bool => str_contains($error, 'Service unavailable')));
    }

    public function test_an_admin_links_and_unlinks_from_the_page(): void
    {
        $this->fakeListing();

        $this->actingAs($this->admin)
            ->from(route('admin.etsy.anuncios.index'))
            ->post(route('admin.etsy.anuncios.ligar', $this->product), ['listing_id' => 7001])
            ->assertRedirect(route('admin.etsy.anuncios.index'))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        $this->assertSame(7001, EtsyListing::query()->sole()->etsy_listing_id);

        $this->actingAs($this->admin)
            ->from(route('admin.etsy.anuncios.index'))
            ->delete(route('admin.etsy.anuncios.desligar', $this->product))
            ->assertRedirect(route('admin.etsy.anuncios.index'));

        $this->assertSame(0, EtsyListing::query()->count());
    }

    public function test_a_deleted_product_can_still_be_unlinked(): void
    {
        $this->fakeListing();
        $this->sync()->link($this->product, 7001);
        $this->product->delete();

        // Ligou-se o anuncio ao duplicado e apagou-se o duplicado: o anuncio
        // tem de poder ser libertado, senao so restaurando o produto.
        $this->actingAs($this->admin)
            ->from(route('admin.etsy.anuncios.index'))
            ->delete(route('admin.etsy.anuncios.desligar', $this->product->id))
            ->assertRedirect(route('admin.etsy.anuncios.index'));

        $this->assertSame(0, EtsyListing::query()->count());
    }

    public function test_a_listing_linked_to_a_deleted_product_says_so(): void
    {
        EtsyListing::query()->create(['product_id' => $this->product->id, 'etsy_listing_id' => 7001, 'state' => 'draft']);
        $this->product->delete();

        Http::fake(['etsy.test/v3/application/shops/4242/listings*' => Http::response(['count' => 1, 'results' => [
            $this->etsyListing(),
        ]])]);

        // Para o ecra nao oferecer uma ligacao a uma pagina que ja nao abre.
        $this->actingAs($this->admin)
            ->get(route('admin.etsy.anuncios.index', ['state' => 'draft']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('listings.0.linkedProduct.name', 'Vaso Espiral')
                ->where('listings.0.linkedProduct.deleted', true));
    }

    public function test_a_refused_link_comes_back_as_a_message_not_a_crash(): void
    {
        $this->fakeListing(['shop_id' => 1]);

        $this->actingAs($this->admin)
            ->from(route('admin.etsy.anuncios.index'))
            ->post(route('admin.etsy.anuncios.ligar', $this->product), ['listing_id' => 7001])
            ->assertRedirect(route('admin.etsy.anuncios.index'))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');
    }

    public function test_only_admins_reach_the_listings(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.etsy.anuncios.index'))->assertForbidden();
        $this->actingAs(User::factory()->production()->create())
            ->post(route('admin.etsy.anuncios.ligar', $this->product), ['listing_id' => 7001])
            ->assertForbidden();
    }
}
