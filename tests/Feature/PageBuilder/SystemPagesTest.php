<?php

namespace Tests\Feature\PageBuilder;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * As paginas de sistema nascem sozinhas, com o conteudo de origem, na
 * primeira visita — e uma loja nova arranca com um site completo.
 */
class SystemPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function publicPages(): array
    {
        return [
            'inicio' => ['/', 'home'],
            'sobre' => ['/sobre', 'about'],
            'personalizado' => ['/personalizado', 'custom'],
            'contacto' => ['/contacto', 'contact'],
            'faq' => ['/faq', 'faq'],
        ];
    }

    #[DataProvider('publicPages')]
    public function test_each_public_page_renders_its_blocks(string $path, string $key): void
    {
        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('store/page')
            ->where('page.key', $key)
            ->where('page.draft', false)
            ->has('page.blocks'));

        $this->assertDatabaseHas('pages', ['key' => $key, 'status' => 'published']);
    }

    public function test_a_page_is_created_only_once(): void
    {
        $this->get('/sobre');
        $this->get('/sobre');

        $this->assertSame(1, Page::query()->where('key', 'about')->count());
    }

    public function test_the_default_content_comes_in_every_language(): void
    {
        $this->get('/en')->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.type', 'hero')
            ->where('page.blocks.0.data.title', '3D-printed pieces, designed with care.')
            ->where('page.blocks.0.data.primary.href', '/en/produtos'));

        $this->get('/fr/sobre')->assertInertia(fn (Assert $page) => $page
            ->where('page.title', 'À propos'));
    }

    public function test_legal_pages_start_as_drafts_hidden_from_visitors(): void
    {
        $this->get('/termos')->assertNotFound();
        $this->get('/privacidade')->assertNotFound();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/termos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('page.draft', true)
                ->where('page.blocks.1.type', 'text'));
    }

    public function test_a_published_page_shows_the_published_version_not_the_draft(): void
    {
        $this->get('/sobre');

        $page = Page::query()->where('key', 'about')->firstOrFail();
        $draft = $page->draft_blocks;
        $draft[0]['content']['pt']['title'] = 'Título ainda por publicar';
        $page->update(['draft_blocks' => $draft]);

        $this->get('/sobre')->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.data.title', 'Sobre nós'));
    }
}
