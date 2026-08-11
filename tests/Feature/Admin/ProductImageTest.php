<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->admin = User::factory()->admin()->create();
        $this->product = Product::factory()->create();
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, UploadedFile>
     */
    private function files(array $names): array
    {
        return array_map(
            fn (string $name): UploadedFile => UploadedFile::fake()->image($name, 800, 800),
            $names,
        );
    }

    private function upload(string ...$names): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.imagens.store', $this->product), [
                'images' => $this->files($names),
            ])
            ->assertRedirect(route('admin.produtos.edit', $this->product));
    }

    public function test_several_images_upload_at_once(): void
    {
        $this->upload('a.jpg', 'b.jpg', 'c.jpg');

        $this->assertDatabaseCount('product_images', 3);

        foreach (ProductImage::all() as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
    }

    /**
     * Sem principal, as listagens, o carrinho, os emails e o OG nao sabem que
     * imagem mostrar — a primeira assume o lugar sozinha.
     */
    public function test_the_first_image_becomes_primary(): void
    {
        $this->upload('a.jpg', 'b.jpg');

        $images = ProductImage::query()->orderBy('sort_order')->get();

        $this->assertTrue($images[0]->is_primary);
        $this->assertFalse($images[1]->is_primary);
    }

    public function test_upload_appends_to_the_end_of_the_gallery(): void
    {
        $this->upload('a.jpg');
        $this->upload('b.jpg');

        $this->assertSame(
            [1, 2],
            ProductImage::query()->orderBy('id')->pluck('sort_order')->all(),
        );
    }

    /**
     * O indice unico PARCIAL product_images_one_primary_per_product so
     * permite uma linha com is_primary = 1 por produto — a transacao tem de
     * desmarcar a antiga ANTES de marcar a nova, ou rebenta a meio.
     */
    public function test_set_primary_moves_the_flag_atomically(): void
    {
        $this->upload('a.jpg', 'b.jpg', 'c.jpg');

        $target = ProductImage::query()->orderBy('sort_order')->get()->last();

        $this->actingAs($this->admin)
            ->patch(route('admin.imagens.principal', $target))
            ->assertRedirect(route('admin.produtos.edit', $this->product));

        $this->assertTrue($target->refresh()->is_primary);
        $this->assertSame(
            1,
            ProductImage::query()->where('product_id', $this->product->id)->where('is_primary', true)->count(),
        );
    }

    public function test_two_products_can_each_have_their_own_primary(): void
    {
        $other = Product::factory()->create();

        $this->upload('a.jpg');

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.imagens.store', $other), [
                'images' => $this->files(['b.jpg']),
            ]);

        $this->assertSame(2, ProductImage::query()->where('is_primary', true)->count());
    }

    public function test_deleting_removes_the_file_and_the_row(): void
    {
        $this->upload('a.jpg', 'b.jpg');

        $image = ProductImage::query()->orderBy('sort_order')->get()->last();
        $path = $image->path;

        $this->actingAs($this->admin)
            ->delete(route('admin.imagens.destroy', $image));

        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_deleting_the_primary_promotes_the_next_one(): void
    {
        $this->upload('a.jpg', 'b.jpg');

        $images = ProductImage::query()->orderBy('sort_order')->get();

        $this->actingAs($this->admin)
            ->delete(route('admin.imagens.destroy', $images[0]));

        $this->assertTrue($images[1]->refresh()->is_primary);
    }

    public function test_deleting_the_last_image_leaves_no_orphan_primary(): void
    {
        $this->upload('a.jpg');

        $image = ProductImage::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('admin.imagens.destroy', $image));

        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_reorder_renumbers_the_gallery(): void
    {
        $this->upload('a.jpg', 'b.jpg', 'c.jpg');

        $ids = ProductImage::query()->orderBy('sort_order')->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.imagens.ordem', $this->product), ['ids' => $reversed]);

        $this->assertSame(
            $reversed,
            ProductImage::query()->orderBy('sort_order')->pluck('id')->all(),
        );
    }

    /**
     * A ordem vem do browser: ids de outro produto nao podem passar.
     */
    public function test_reorder_ignores_images_of_another_product(): void
    {
        $this->upload('a.jpg');

        $stranger = ProductImage::factory()->create();
        $before = $stranger->sort_order;

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.imagens.ordem', $this->product), [
                'ids' => [$stranger->id],
            ]);

        $this->assertSame($before, $stranger->refresh()->sort_order);
    }

    public function test_the_alt_text_can_be_edited(): void
    {
        $this->upload('a.jpg');

        $image = ProductImage::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->patch(route('admin.imagens.update', $image), ['alt' => 'Vaso visto de cima']);

        $this->assertSame('Vaso visto de cima', $image->refresh()->alt);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.imagens.store', $this->product), [
                'images' => [UploadedFile::fake()->create('malware.php', 10, 'application/x-php')],
            ])
            ->assertSessionHasErrors('images.0');

        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_a_file_above_five_megabytes_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.imagens.store', $this->product), [
                'images' => [UploadedFile::fake()->image('enorme.jpg')->size(6000)],
            ])
            ->assertSessionHasErrors('images.0');
    }

    public function test_more_than_ten_at_once_is_rejected(): void
    {
        $names = array_map(fn (int $i): string => "img-{$i}.jpg", range(1, 11));

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.imagens.store', $this->product), [
                'images' => $this->files($names),
            ])
            ->assertSessionHasErrors('images');

        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_non_admins_cannot_upload(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.produtos.imagens.store', $this->product), [
                'images' => $this->files(['a.jpg']),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('product_images', 0);
    }

    /**
     * O servico e a unica via de escrita — testado tambem sem passar pelo
     * HTTP, porque e ele que a migracao referencia por nome.
     */
    public function test_the_service_keeps_a_single_primary_across_many_swaps(): void
    {
        $service = app(ImageService::class);

        $images = collect(['a.jpg', 'b.jpg', 'c.jpg'])
            ->map(fn (string $name): ProductImage => $service->store(
                $this->product,
                UploadedFile::fake()->image($name),
            ));

        foreach ($images as $image) {
            $service->setPrimary($image);
        }

        $this->assertSame(1, ProductImage::query()->where('is_primary', true)->count());
        $this->assertTrue($images->last()->refresh()->is_primary);
    }
}
