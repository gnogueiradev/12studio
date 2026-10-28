<?php

namespace Tests\Feature\PageBuilder;

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use App\PageBuilder\PageBuilderService;
use App\PageBuilder\Pages;
use App\PageBuilder\ReservedSlugs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PageBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PageBuilderService
    {
        return app(PageBuilderService::class);
    }

    private function landing(array $blocks = []): Page
    {
        $page = $this->service()->createLanding(['name' => 'Natal', 'slug' => 'natal']);

        return $this->service()->saveDraft($page, $blocks ?: [['type' => 'banner', 'content' => ['pt' => ['title' => 'Feliz Natal']]]]);
    }

    public function test_publishing_copies_the_draft_and_keeps_a_revision(): void
    {
        $page = $this->landing();
        $this->assertFalse($page->isPublished());

        $page = $this->service()->publish($page, User::factory()->admin()->create());

        $this->assertTrue($page->isPublished());
        $this->assertSame($page->draft_blocks, $page->published_blocks);
        $this->assertSame(1, $page->revisions()->count());
    }

    public function test_only_the_last_revisions_are_kept(): void
    {
        $page = $this->landing();

        foreach (range(1, PageRevision::KEEP + 5) as $i) {
            $this->service()->publish($page);
        }

        $this->assertSame(PageRevision::KEEP, $page->revisions()->count());
    }

    public function test_restoring_a_revision_brings_it_back_as_the_draft(): void
    {
        $page = $this->service()->publish($this->landing());
        $revision = $page->revisions()->firstOrFail();

        $this->service()->saveDraft($page, [['type' => 'banner', 'content' => ['pt' => ['title' => 'Outro']]]]);
        $this->service()->restore($page, $revision);

        $this->assertSame('Feliz Natal', $page->refresh()->draft_blocks[0]['content']['pt']['title']);
    }

    public function test_reserved_and_taken_slugs_are_refused(): void
    {
        foreach (['admin', 'produtos', 'login', 'en', 'sobre', 'categoria', 'avisar', 'Natal Grande', '-natal', 'natal--2'] as $slug) {
            try {
                $this->service()->createLanding(['name' => 'X', 'slug' => $slug]);
                $this->fail("O endereco /{$slug} devia ser recusado.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('slug', $exception->errors());
            }
        }

        $this->landing();
        $this->expectException(ValidationException::class);
        $this->service()->createLanding(['name' => 'Outra', 'slug' => 'natal']);
    }

    public function test_no_public_route_is_ever_shadowed_by_a_landing(): void
    {
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{') || $uri === '/') {
                continue;
            }

            $first = explode('/', $uri)[0];
            $this->assertTrue(ReservedSlugs::isReserved($first), "/{$first} nao esta reservado.");

            $matched = Route::getRoutes()->match(Request::create('/'.$uri));
            $this->assertNotSame('store.landing', $matched->getName(), "/{$uri} cai na rota das landings.");
        }
    }

    public function test_system_pages_cannot_be_deleted_or_unpublished(): void
    {
        $home = app(Pages::class)->system('home');

        $this->expectException(ValidationException::class);
        $this->service()->unpublish($home);
    }

    public function test_legal_pages_can_be_unpublished(): void
    {
        $terms = $this->service()->publish(app(Pages::class)->system('terms'));

        $this->assertSame('unpublished', $this->service()->unpublish($terms)->status);
    }

    public function test_seo_is_limited(): void
    {
        $page = $this->landing();

        $this->expectException(ValidationException::class);
        $this->service()->saveDraft($page, $page->draft_blocks, ['pt' => ['title' => str_repeat('a', 71)]]);
    }

    public function test_duplicating_makes_a_new_draft_landing(): void
    {
        $copy = $this->service()->duplicate(app(Pages::class)->system('about'));

        $this->assertSame('landing', $copy->type);
        $this->assertSame('draft', $copy->status);
        $this->assertSame('sobre-copia', $copy->slug);
        $this->assertNotEmpty($copy->draft_blocks);
    }
}
