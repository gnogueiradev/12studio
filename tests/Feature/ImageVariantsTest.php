<?php

namespace Tests\Feature;

use App\Images\ImageVariants;
use App\Jobs\GenerateImageVariants;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * As versoes reduzidas das fotografias: a montra serve WebP do tamanho certo
 * para cada ecra em vez de originais de 5 MB, e o Open Graph leva um JPEG.
 */
class ImageVariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function upload(int $width, int $height = 600): ProductImage
    {
        return app(ImageService::class)->store(
            Product::factory()->create(),
            UploadedFile::fake()->image('foto.jpg', $width, $height),
        )->refresh();
    }

    public function test_an_upload_gets_webp_widths_up_to_its_own_and_an_og_jpeg(): void
    {
        $image = $this->upload(800);

        $this->assertSame(800, $image->width);
        $this->assertSame(600, $image->height);
        $this->assertSame([320, 640], array_keys($image->variants['webp']));

        foreach ($image->variants['webp'] as $path) {
            Storage::disk('public')->assertExists($path);
            $this->assertStringEndsWith('.webp', $path);
            $this->assertStringStartsWith('products/variants/', $path);
        }

        Storage::disk('public')->assertExists($image->variants['og']);
        $this->assertStringEndsWith('-og.jpg', $image->variants['og']);
    }

    public function test_variants_are_never_upscaled(): void
    {
        $image = $this->upload(200, 150);

        $this->assertSame([200], array_keys($image->variants['webp']));

        [$width] = getimagesizefromstring(Storage::disk('public')->get($image->variants['og']));
        $this->assertSame(200, $width);
    }

    public function test_the_responsive_payload_has_a_srcset_once_the_variants_exist(): void
    {
        $image = $this->upload(800);
        $responsive = $image->responsive('Vaso');

        $this->assertSame($image->url, $responsive['src']);
        $this->assertStringContainsString('-320.webp 320w', (string) $responsive['srcset']);
        $this->assertStringContainsString('-640.webp 640w', (string) $responsive['srcset']);
        $this->assertSame(800, $responsive['width']);
        $this->assertSame('Vaso', $responsive['alt']);
        $this->assertStringEndsWith('-og.jpg', $image->og_url);
    }

    public function test_before_the_job_runs_the_original_is_served(): void
    {
        Queue::fake();

        $image = $this->upload(800);

        Queue::assertPushed(GenerateImageVariants::class, fn (GenerateImageVariants $job): bool => $job->id === $image->id);
        $this->assertNull($image->responsive()['srcset']);
        $this->assertSame($image->url, $image->og_url);
    }

    public function test_deleting_an_image_deletes_its_variants(): void
    {
        $image = $this->upload(800);
        $files = [...array_values($image->variants['webp']), $image->variants['og']];

        app(ImageService::class)->delete($image);

        foreach ($files as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_an_image_deleted_before_the_job_runs_is_skipped(): void
    {
        (new GenerateImageVariants(ProductImage::class, 999))->handle(app(ImageVariants::class));

        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_an_unreadable_file_leaves_the_original_alone(): void
    {
        Storage::disk('public')->put('products/estragada.jpg', 'isto nao e uma imagem');

        $image = ProductImage::factory()->create(['path' => 'products/estragada.jpg']);

        (new GenerateImageVariants(ProductImage::class, $image->id))->handle(app(ImageVariants::class));

        $this->assertNull($image->refresh()->variants);
    }

    public function test_the_backfill_command_queues_only_images_without_variants(): void
    {
        $done = $this->upload(800);

        Queue::fake();

        $missing = ProductImage::factory()->create(['path' => 'products/antiga.jpg']);

        $this->artisan('images:variants')->assertSuccessful();

        Queue::assertPushed(GenerateImageVariants::class, 1);
        Queue::assertPushed(GenerateImageVariants::class, fn (GenerateImageVariants $job): bool => $job->id === $missing->id);

        $this->artisan('images:variants', ['--force' => true])->assertSuccessful();

        Queue::assertPushed(GenerateImageVariants::class, fn (GenerateImageVariants $job): bool => $job->id === $done->id);
    }
}
