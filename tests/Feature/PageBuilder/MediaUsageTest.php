<?php

namespace Tests\Feature\PageBuilder;

use App\Models\Media;
use App\PageBuilder\PageBuilderService;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Uma imagem em uso (num campo de um elemento ou no fundo de um contentor)
 * nao se apaga.
 */
class MediaUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_container_background_counts_as_usage_and_blocks_deletion(): void
    {
        $media = Media::factory()->create();
        $other = Media::factory()->create();
        $service = app(PageBuilderService::class);

        $page = $service->createLanding(['name' => 'Natal', 'slug' => 'natal']);
        $service->saveDraft($page, [[
            'type' => 'container',
            'children' => [[
                'type' => 'container',
                'style' => ['bg_image' => ['tablet' => $other->id, 'mobile' => $media->id]],
                'children' => [],
            ]],
        ]]);

        $this->assertSame(['Natal'], app(MediaService::class)->usage($media));
        $this->assertSame(['Natal'], app(MediaService::class)->usage($other));

        try {
            app(MediaService::class)->delete($media);
            $this->fail('Uma imagem de fundo em uso nao se apaga.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('media', $exception->errors());
        }

        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_a_published_only_background_counts_and_an_unused_image_does_not(): void
    {
        $media = Media::factory()->create();
        $free = Media::factory()->create();
        $service = app(PageBuilderService::class);

        $page = $service->createLanding(['name' => 'Natal', 'slug' => 'natal']);
        $service->saveDraft($page, [['type' => 'container', 'style' => ['bg_image' => ['desktop' => $media->id]], 'children' => []]]);
        $service->publish($page);
        // O rascunho ja nao a usa, o publicado ainda sim.
        $service->saveDraft($page, []);

        $this->assertSame(['Natal'], app(MediaService::class)->usage($media));
        $this->assertSame([], app(MediaService::class)->usage($free));
    }
}
