<?php

namespace Tests\Feature\Admin;

use App\Models\EtsyListing;
use App\Models\EtsyVariant;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Etsy\FakesEtsy;
use Tests\TestCase;

/**
 * O anuncio de um produto na Etsy, visto do backoffice: o que la vai, o que
 * falta, e os botoes para guardar e enviar.
 */
class EtsyProductPageTest extends TestCase
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

    private function writes(): int
    {
        return $this->etsyRequests()->filter(fn (Request $request): bool => $request->method() !== 'GET')->count();
    }

    // ── Ver ────────────────────────────────────────────────────────────────

    public function test_the_page_shows_what_would_go_to_etsy(): void
    {
        ProductImage::factory()->for($this->product)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.etsy.show', $this->product))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/produtos/etsy')
                ->where('product.name', 'Vaso Espiral')
                ->where('listing.onEtsy', false)
                // O que o anuncio diria hoje, vindo do produto.
                ->where('effective.title', 'Vaso Espiral')
                ->where('effective.shippingProfileId', 555)
                ->has('variants', 2)
                ->where('variants.0.sku', 'VASO-P')
                ->where('variants.0.label', 'Preto')
                ->where('variants.0.sitePriceCents', 2490)
                ->where('variants.0.etsyPriceCents', null)
                ->where('variants.0.effectivePriceCents', 2490)
                ->where('variants.0.quantity', 5)
                ->has('images', 1)
                ->where('images.0.excluded', false)
                ->where('blockers', [])
                ->has('options.shippingProfiles', 1)
                ->has('options.sections', 2));
    }

    public function test_it_says_what_is_missing_instead_of_letting_etsy_say_it(): void
    {
        Variant::query()->where('product_id', $this->product->id)->update(['stock' => 0]);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.etsy.show', $this->product))
            ->assertInertia(fn (Assert $page) => $page
                ->has('blockers', 1)
                ->where('blockers.0', fn (string $blocker): bool => str_contains($blocker, 'stock')));
    }

    public function test_the_page_of_a_deleted_product_is_gone(): void
    {
        $this->product->delete();

        $this->actingAs($this->admin)->get(route('admin.produtos.etsy.show', $this->product->id))->assertNotFound();
    }

    // ── Guardar ────────────────────────────────────────────────────────────

    public function test_saving_keeps_the_etsy_content_here_and_sends_nothing(): void
    {
        $black = Variant::query()->where('sku', 'VASO-P')->sole();
        $photo = ProductImage::factory()->for($this->product)->create();

        $this->actingAs($this->admin)
            ->from(route('admin.produtos.etsy.show', $this->product))
            ->put(route('admin.produtos.etsy.guardar', $this->product), [
                'title' => 'Spiral Vase — 3D printed',
                'description' => "A vase.\nPrinted in Porto.",
                'tags' => ['vase', 'decor'],
                'taxonomy_id' => 999,
                'shipping_profile_id' => 555,
                'shop_section_id' => 31,
                'variants' => [['variant_id' => $black->id, 'etsy_price' => '29,90', 'enabled' => true]],
                'excluded_image_ids' => [$photo->id],
            ])
            ->assertRedirect(route('admin.produtos.etsy.show', $this->product))
            ->assertSessionHasNoErrors();

        $listing = EtsyListing::query()->where('product_id', $this->product->id)->sole();
        $this->assertSame('Spiral Vase — 3D printed', $listing->title);
        $this->assertSame(['vase', 'decor'], $listing->tags);
        $this->assertSame(999, $listing->taxonomy_id);
        $this->assertSame(31, $listing->shop_section_id);
        $this->assertNull($listing->etsy_listing_id);

        $this->assertSame(2990, EtsyVariant::query()->where('variant_id', $black->id)->sole()->etsy_price_cents);
        $this->assertTrue($listing->images()->where('product_image_id', $photo->id)->sole()->excluded);
        $this->assertSame(0, $this->writes());
    }

    public function test_an_empty_field_goes_back_to_what_the_product_says(): void
    {
        $black = Variant::query()->where('sku', 'VASO-P')->sole();
        $listing = EtsyListing::query()->create(['product_id' => $this->product->id, 'title' => 'Antigo', 'taxonomy_id' => 999]);
        EtsyVariant::query()->create(['variant_id' => $black->id, 'listing_id' => $listing->id, 'etsy_price_cents' => 2990]);

        $this->actingAs($this->admin)
            ->put(route('admin.produtos.etsy.guardar', $this->product), [
                'title' => '',
                'taxonomy_id' => null,
                'variants' => [['variant_id' => $black->id, 'etsy_price' => '', 'enabled' => true]],
            ])
            ->assertSessionHasNoErrors();

        $listing->refresh();
        $this->assertNull($listing->title);
        $this->assertNull($listing->taxonomy_id);
        // Sem preco proprio volta a valer o do site.
        $this->assertNull(EtsyVariant::query()->where('variant_id', $black->id)->sole()->etsy_price_cents);
    }

    public function test_the_limits_of_etsy_are_checked_here(): void
    {
        $other = Variant::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.produtos.etsy.guardar', $this->product), [
                'title' => str_repeat('a', 141),
                'tags' => array_map(fn (int $i): string => "tag {$i}", range(1, 14)),
                'who_made' => 'aliens',
                'variants' => [['variant_id' => $other->id, 'etsy_price' => '10', 'enabled' => true]],
            ])
            ->assertSessionHasErrors(['title', 'tags', 'who_made', 'variants.0.variant_id']);

        $this->assertSame(0, EtsyListing::query()->count());
    }

    // ── Enviar ─────────────────────────────────────────────────────────────

    public function test_sending_creates_the_draft_and_says_so(): void
    {
        $this->fakeEtsyListingWrites();

        $this->actingAs($this->admin)
            ->from(route('admin.produtos.etsy.show', $this->product))
            ->post(route('admin.produtos.etsy.enviar', $this->product))
            ->assertRedirect(route('admin.produtos.etsy.show', $this->product))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        $this->assertSame(7100, EtsyListing::query()->sole()->etsy_listing_id);
    }

    public function test_what_etsy_refuses_comes_back_as_a_message(): void
    {
        $this->fakeEtsyListingWrites(['create' => Http::response(['error' => 'Invalid taxonomy_id'], 400)]);

        $this->actingAs($this->admin)
            ->from(route('admin.produtos.etsy.show', $this->product))
            ->post(route('admin.produtos.etsy.enviar', $this->product))
            ->assertRedirect(route('admin.produtos.etsy.show', $this->product))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error')
            ->assertSessionHas('inertia.flash_data.toast.message', fn (string $m): bool => str_contains($m, 'Invalid taxonomy_id'));
    }

    // ── Video ──────────────────────────────────────────────────────────────

    public function test_a_video_is_kept_here_until_the_next_push(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.etsy.video', $this->product), [
                'video' => UploadedFile::fake()->create('vaso.mp4', 2048, 'video/mp4'),
            ])
            ->assertSessionHasNoErrors();

        $listing = EtsyListing::query()->where('product_id', $this->product->id)->sole();
        $this->assertNotNull($listing->video_path);
        Storage::disk('public')->assertExists($listing->video_path);
        $this->assertSame(0, $this->writes());
    }

    public function test_only_videos_are_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.etsy.video', $this->product), [
                'video' => UploadedFile::fake()->create('vaso.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors('video');
    }

    public function test_removing_the_video_takes_it_from_etsy_too(): void
    {
        Storage::disk('public')->put('etsy-videos/vaso.mp4', 'bytes');
        EtsyListing::query()->create([
            'product_id' => $this->product->id, 'etsy_listing_id' => 7100, 'state' => 'draft',
            'video_path' => 'etsy-videos/vaso.mp4', 'etsy_video_id' => 5001,
        ]);
        $this->fakeEtsyListingWrites();

        $this->actingAs($this->admin)
            ->delete(route('admin.produtos.etsy.video.remover', $this->product))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        $request = $this->etsyRequests()->filter(fn (Request $r): bool => $r->method() === 'DELETE')->sole();
        $this->assertStringEndsWith('/shops/4242/listings/7100/videos/5001', $request->url());

        $listing = EtsyListing::query()->sole();
        $this->assertNull($listing->video_path);
        $this->assertNull($listing->etsy_video_id);
        Storage::disk('public')->assertMissing('etsy-videos/vaso.mp4');
    }

    // ── Quem entra ─────────────────────────────────────────────────────────

    public function test_only_admins_reach_the_listing_of_a_product(): void
    {
        foreach ([User::factory()->create(), User::factory()->production()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.produtos.etsy.show', $this->product))->assertForbidden();
            $this->actingAs($user)->put(route('admin.produtos.etsy.guardar', $this->product), ['title' => 'X'])->assertForbidden();
            $this->actingAs($user)->post(route('admin.produtos.etsy.enviar', $this->product))->assertForbidden();
        }

        $this->assertSame(0, $this->writes());
    }
}
