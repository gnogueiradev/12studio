<?php

namespace Tests\Feature\Etsy;

use App\Etsy\EtsyApiException;
use App\Etsy\EtsyException;
use App\Etsy\EtsyListingSync;
use App\Jobs\UploadEtsyListingImage;
use App\Jobs\UploadEtsyListingVideo;
use App\Models\EtsyListing;
use App\Models\EtsyVariant;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Enviar um produto para a Etsy: o rascunho, o inventario, as fotografias e o
 * video. Os testes guardam o que sai para la e, sobretudo, o que NAO pode
 * sair duas vezes.
 */
class EtsyListingPushTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->configureEtsy();
        $this->connectEtsy();
        $this->fakeEtsyShopConfig();

        $this->product = $this->etsyReadyProduct();
    }

    private function push(): EtsyListing
    {
        return app(EtsyListingSync::class)->push($this->product->fresh());
    }

    /**
     * @return array<int, Request>
     */
    private function writes(?string $method = null, string $needle = ''): array
    {
        return $this->etsyRequests($needle)
            ->filter(fn (Request $request): bool => $request->method() !== 'GET' && ($method === null || $request->method() === $method))
            ->values()
            ->all();
    }

    private function photo(int $order, array $attributes = []): ProductImage
    {
        $image = ProductImage::factory()->for($this->product)->create(['sort_order' => $order, ...$attributes]);
        Storage::disk('public')->put($image->path, "bytes-da-foto-{$order}");

        return $image;
    }

    // ── O rascunho ─────────────────────────────────────────────────────────

    public function test_the_first_push_creates_a_draft_and_then_its_inventory(): void
    {
        $this->fakeEtsyListingWrites();
        $this->freezeTime();

        $listing = $this->push();

        [$create, $inventory] = $this->writes();

        $this->assertSame('POST', $create->method());
        $this->assertStringEndsWith('/shops/4242/listings', $create->url());
        $this->assertStringStartsWith('application/x-www-form-urlencoded', $create->header('Content-Type')[0]);
        $this->assertSame('Vaso Espiral', $create['title']);
        $this->assertSame('1234', $create['taxonomy_id']);
        $this->assertSame('555', $create['shipping_profile_id']);
        $this->assertSame('801', $create['readiness_state_id']);
        $this->assertSame('PLA', $create['materials']);
        // O que a Etsy exige para criar: o total e o preco mais baixo. O
        // inventario, a seguir, e que diz quanto e a quanto de cada variacao.
        $this->assertSame('8', $create['quantity']);
        $this->assertSame('24.90', $create['price']);

        $this->assertSame('PUT', $inventory->method());
        $this->assertStringEndsWith('/listings/7100/inventory', $inventory->url());
        $this->assertStringStartsWith('application/json', $inventory->header('Content-Type')[0]);
        $this->assertSame('VASO-P', $inventory['products'][0]['sku']);
        $this->assertSame(24.9, $inventory['products'][0]['offerings'][0]['price']);
        $this->assertSame(5, $inventory['products'][0]['offerings'][0]['quantity']);

        $this->assertSame(7100, $listing->etsy_listing_id);
        $this->assertSame('draft', $listing->state);
        $this->assertSame('https://www.etsy.com/listing/7100', $listing->url);
        $this->assertTrue(now()->startOfSecond()->equalTo($listing->last_pushed_at));
        $this->assertNotNull($listing->last_push_hash);
        $this->assertNull($listing->last_error);
    }

    public function test_the_ids_etsy_gave_each_variation_are_kept(): void
    {
        $this->fakeEtsyListingWrites();

        $this->push();

        $black = EtsyVariant::query()->where('variant_id', Variant::query()->where('sku', 'VASO-P')->value('id'))->sole();
        $white = EtsyVariant::query()->where('variant_id', Variant::query()->where('sku', 'VASO-B')->value('id'))->sole();

        $this->assertSame(9301, $black->etsy_product_id);
        $this->assertSame(9401, $black->etsy_offering_id);
        $this->assertSame(5, $black->last_quantity_pushed);
        $this->assertSame(9302, $white->etsy_product_id);
    }

    public function test_the_second_push_updates_instead_of_creating_again(): void
    {
        $this->fakeEtsyListingWrites();
        $this->push();

        $this->fakeEtsyListingWrites();
        $this->product->update(['name' => 'Vaso Espiral XL']);
        $this->push();

        [$update, $inventory] = $this->writes();

        $this->assertSame('PATCH', $update->method());
        $this->assertStringEndsWith('/shops/4242/listings/7100', $update->url());
        $this->assertSame('Vaso Espiral XL', $update['title']);
        // O perfil de processamento nao e um campo do anuncio na edicao: vai
        // em cada oferta do inventario.
        $this->assertArrayNotHasKey('readiness_state_id', $update->data());
        $this->assertArrayNotHasKey('quantity', $update->data());
        $this->assertSame('PUT', $inventory->method());
        $this->assertSame(1, EtsyListing::query()->count());
    }

    public function test_a_draft_created_is_never_created_twice(): void
    {
        $this->fakeEtsyListingWrites(['inventory' => Http::response(['error' => 'Invalid property'], 400)]);

        try {
            $this->push();
            $this->fail('Devia ter lancado.');
        } catch (EtsyApiException $exception) {
            $this->assertStringContainsString('Invalid property', $exception->getMessage());
        }

        // O rascunho ficou criado la. O numero dele tem de ficar ca JA, senao
        // a tentativa seguinte criava outro igual.
        $listing = EtsyListing::query()->sole();
        $this->assertSame(7100, $listing->etsy_listing_id);
        $this->assertStringContainsString('Invalid property', (string) $listing->last_error);
        $this->assertNull($listing->last_pushed_at);

        $this->fakeEtsyListingWrites();
        $this->push();

        $this->assertCount(0, $this->writes('POST', '/shops/4242/listings'));
        $this->assertCount(1, $this->writes('PATCH'));
        $this->assertNull(EtsyListing::query()->sole()->last_error);
    }

    public function test_what_blocks_the_listing_stops_before_anything_leaves(): void
    {
        Variant::query()->where('product_id', $this->product->id)->update(['stock' => 0]);
        $this->fakeEtsyListingWrites();

        try {
            $this->push();
            $this->fail('Devia ter lancado.');
        } catch (EtsyException $exception) {
            $this->assertStringContainsString('stock', $exception->getMessage());
        }

        $this->assertSame([], $this->writes());
        $this->assertSame(0, EtsyListing::query()->whereNotNull('etsy_listing_id')->count());
    }

    // ── Fotografias ────────────────────────────────────────────────────────

    public function test_each_photo_goes_up_once_in_gallery_order(): void
    {
        $first = $this->photo(1, ['alt' => 'De frente']);
        $second = $this->photo(2);
        $this->fakeEtsyListingWrites();

        $listing = $this->push();

        $uploads = $this->writes('POST', '/images');
        $this->assertCount(2, $uploads);
        $this->assertStringEndsWith('/shops/4242/listings/7100/images', $uploads[0]->url());
        $this->assertTrue($uploads[0]->hasFile('image', 'bytes-da-foto-1'));
        $this->assertSame('1', $this->multipartField($uploads[0], 'rank'));
        $this->assertSame('De frente', $this->multipartField($uploads[0], 'alt_text'));
        $this->assertSame('2', $this->multipartField($uploads[1], 'rank'));

        $this->assertSame(
            [$first->id => 8101, $second->id => 8102],
            $listing->images()->orderBy('rank')->pluck('etsy_image_id', 'product_image_id')->all(),
        );

        // Enviar outra vez sem mexer nas fotos nao as volta a carregar.
        $this->fakeEtsyListingWrites();
        $this->push();

        $this->assertSame([], $this->writes('POST', '/images'));
    }

    public function test_an_excluded_photo_stays_here_and_leaves_etsy(): void
    {
        $kept = $this->photo(1);
        $hidden = $this->photo(2);
        $this->fakeEtsyListingWrites();
        $listing = $this->push();

        $listing->images()->where('product_image_id', $hidden->id)->update(['excluded' => true]);

        $this->fakeEtsyListingWrites();
        $this->push();

        $deletes = $this->writes('DELETE');
        $this->assertCount(1, $deletes);
        $this->assertStringEndsWith('/shops/4242/listings/7100/images/8102', $deletes[0]->url());

        $row = $listing->images()->where('product_image_id', $hidden->id)->sole();
        $this->assertTrue($row->excluded);
        $this->assertNull($row->etsy_image_id);
        $this->assertSame(8101, $listing->images()->where('product_image_id', $kept->id)->value('etsy_image_id'));
        $this->assertDatabaseHas('product_images', ['id' => $hidden->id]);
    }

    public function test_a_photo_deleted_here_is_deleted_there(): void
    {
        $this->photo(1);
        $gone = $this->photo(2);
        $this->fakeEtsyListingWrites();
        $listing = $this->push();

        $gone->delete();

        $this->fakeEtsyListingWrites();
        $this->push();

        $this->assertStringEndsWith('/images/8102', $this->writes('DELETE')[0]->url());
        $this->assertSame(1, $listing->images()->count());
    }

    public function test_the_gallery_of_the_product_replaces_what_was_on_the_listing(): void
    {
        // O anuncio foi ligado com duas fotos postas a mao na Etsy.
        $listing = EtsyListing::query()->create(['product_id' => $this->product->id, 'etsy_listing_id' => 7100, 'state' => 'draft']);
        $listing->images()->create(['etsy_image_id' => 81, 'rank' => 1]);
        $listing->images()->create(['etsy_image_id' => 82, 'rank' => 2]);
        $this->photo(1);
        $this->fakeEtsyListingWrites();

        $this->push();

        // Primeiro sobem as novas, so depois saem as antigas: o anuncio nunca
        // fica sem fotografia nenhuma.
        $sequence = array_map(
            fn (Request $request): string => $request->method().' '.basename($request->url()),
            array_values(array_filter($this->writes(), fn (Request $r): bool => str_contains($r->url(), '/images'))),
        );
        $this->assertSame(['POST images', 'DELETE 81', 'DELETE 82'], $sequence);
        $this->assertSame([8101], $listing->images()->pluck('etsy_image_id')->all());
    }

    public function test_without_photos_here_the_ones_on_etsy_are_left_alone(): void
    {
        $listing = EtsyListing::query()->create(['product_id' => $this->product->id, 'etsy_listing_id' => 7100, 'state' => 'draft']);
        $listing->images()->create(['etsy_image_id' => 81, 'rank' => 1]);
        $this->fakeEtsyListingWrites();

        $this->push();

        $this->assertSame([], $this->writes('DELETE'));
        $this->assertSame([81], $listing->images()->pluck('etsy_image_id')->all());
    }

    public function test_a_photo_etsy_refuses_is_not_marked_as_sent(): void
    {
        $photo = $this->photo(1);
        $this->fakeEtsyListingWrites(['image' => Http::response(['error' => 'Image is too small'], 400)]);

        $listing = $this->push();

        // O anuncio e o inventario foram; a fotografia nao. Fica dito, e a
        // proxima tentativa volta a carrega-la.
        $this->assertNull($listing->images()->where('product_image_id', $photo->id)->value('etsy_image_id'));
        $this->assertStringContainsString('Image is too small', (string) $listing->fresh()->last_error);
    }

    public function test_with_a_real_queue_the_push_says_what_is_still_on_its_way(): void
    {
        // Em producao a fila e a `database`: o push() devolve antes de as
        // fotografias terem subido, e quem responde ao dono tem de o saber.
        Queue::fake([UploadEtsyListingImage::class, UploadEtsyListingVideo::class]);
        Storage::disk('public')->put('etsy-videos/vaso.mp4', 'bytes-do-video');
        EtsyListing::query()->create(['product_id' => $this->product->id, 'video_path' => 'etsy-videos/vaso.mp4']);
        $this->photo(1);
        $this->photo(2);
        $this->fakeEtsyListingWrites();

        $listing = $this->push();

        $this->assertSame([], $this->writes('POST', '/images'));
        $this->assertSame(['images' => 2, 'video' => true], app(EtsyListingSync::class)->pendingUploads($listing));
        Queue::assertPushed(UploadEtsyListingImage::class, 2);
        Queue::assertPushed(UploadEtsyListingVideo::class, 1);
    }

    public function test_once_everything_went_up_nothing_is_pending(): void
    {
        $this->photo(1);
        $this->fakeEtsyListingWrites();

        $this->assertSame(['images' => 0, 'video' => false], app(EtsyListingSync::class)->pendingUploads($this->push()));
    }

    // ── Video ──────────────────────────────────────────────────────────────

    public function test_the_video_goes_up_once(): void
    {
        Storage::disk('public')->put('etsy-videos/vaso.mp4', 'bytes-do-video');
        EtsyListing::query()->create(['product_id' => $this->product->id, 'video_path' => 'etsy-videos/vaso.mp4']);
        $this->fakeEtsyListingWrites();

        $listing = $this->push();

        $upload = $this->writes('POST', '/videos')[0];
        $this->assertStringEndsWith('/shops/4242/listings/7100/videos', $upload->url());
        $this->assertTrue($upload->hasFile('video', 'bytes-do-video'));
        $this->assertSame(5001, $listing->fresh()->etsy_video_id);

        $this->fakeEtsyListingWrites();
        $this->push();

        $this->assertSame([], $this->writes('POST', '/videos'));
    }

    // ── Atributos ──────────────────────────────────────────────────────────

    public function test_the_attributes_of_the_category_follow_the_listing(): void
    {
        EtsyListing::query()->create(['product_id' => $this->product->id, 'properties' => [
            ['property_id' => 200, 'value_ids' => [1], 'values' => ['Black']],
        ]]);
        $this->fakeEtsyListingWrites();

        $this->push();

        $request = $this->writes('PUT', '/properties/200')[0];
        $this->assertStringEndsWith('/shops/4242/listings/7100/properties/200', $request->url());
        $this->assertSame('1', $request['value_ids']);
        $this->assertSame('Black', $request['values']);
    }
}
