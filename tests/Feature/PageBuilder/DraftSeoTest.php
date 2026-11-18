<?php

namespace Tests\Feature\PageBuilder;

use App\Models\Page;
use App\Models\User;
use App\PageBuilder\PageBuilderService;
use App\PageBuilder\Pages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * O SEO de uma pagina vive no rascunho como os blocos: o que se escreve no
 * editor so vai para o ar ao publicar, e descartar o rascunho repoe-no.
 */
class DraftSeoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
        $this->admin = User::factory()->admin()->create();
    }

    private function service(): PageBuilderService
    {
        return app(PageBuilderService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function banner(string $title): array
    {
        return ['type' => 'banner', 'content' => ['pt' => ['title' => $title]]];
    }

    /** Uma landing publicada com este titulo de SEO. */
    private function publishedLanding(string $seoTitle): Page
    {
        $page = $this->service()->createLanding(['name' => 'Natal', 'slug' => 'natal']);
        $this->service()->saveDraft($page, [$this->banner('Feliz Natal')], ['pt' => ['title' => $seoTitle]]);

        return $this->service()->publish($page);
    }

    /**
     * O rascunho como o editor o grava (autosave), com o marcador certo. O
     * SEO vai inteiro, como o editor o manda.
     *
     * @param  string|array<string, mixed>  $seo
     */
    private function autosave(Page $page, string|array $seo): void
    {
        $page = $page->fresh();

        $this->actingAs($this->admin)
            ->putJson(route('admin.montra.paginas.rascunho', $page), [
                'blocks' => $page->draft_blocks,
                'seo' => is_string($seo) ? ['pt' => ['title' => $seo]] : $seo,
                'version' => $page->draftVersion(),
            ])
            ->assertOk();
    }

    private function assertStoreTitle(string $path, string $title): void
    {
        auth()->logout();

        $this->get($path)
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia->where('page.title', $title));
    }

    private function assertEditorSeo(Page $page, string $title, bool $hasChanges): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.montra.paginas.edit', $page))
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('page.seo.pt.title', $title)
                ->where('page.hasChanges', $hasChanges));
    }

    public function test_seo_written_in_the_draft_does_not_change_what_the_store_serves(): void
    {
        $page = $this->publishedLanding('Natal no ar');

        $this->autosave($page, 'Natal por publicar');

        $this->assertStoreTitle('/natal', 'Natal no ar');
        $this->assertEditorSeo($page, 'Natal por publicar', hasChanges: true);
    }

    public function test_publishing_takes_the_draft_seo_to_the_store_and_the_revision(): void
    {
        $page = $this->publishedLanding('Natal no ar');
        $this->autosave($page, 'Natal novo');

        $this->actingAs($this->admin)
            ->post(route('admin.montra.paginas.publicar', $page))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Natal novo', $page->revisions()->firstOrFail()->seo['pt']['title'] ?? null);
        $this->assertEditorSeo($page, 'Natal novo', hasChanges: false);
        $this->assertStoreTitle('/natal', 'Natal novo');
    }

    public function test_discarding_the_draft_brings_the_published_seo_back(): void
    {
        $page = $this->publishedLanding('Natal no ar');
        $this->autosave($page, 'Natal por publicar');

        $this->actingAs($this->admin)
            ->post(route('admin.montra.paginas.descartar', $page))
            ->assertRedirect();

        $this->assertEditorSeo($page, 'Natal no ar', hasChanges: false);
        $this->assertStoreTitle('/natal', 'Natal no ar');
    }

    public function test_restoring_a_revision_puts_its_seo_in_the_draft_only(): void
    {
        $page = $this->publishedLanding('Primeiro');
        $first = $page->revisions()->firstOrFail();
        $this->autosave($page, 'Segundo');
        $this->service()->publish($page->fresh());

        $this->actingAs($this->admin)
            ->post(route('admin.montra.paginas.repor', [$page, $first]))
            ->assertRedirect();

        $this->assertEditorSeo($page, 'Primeiro', hasChanges: true);
        $this->assertStoreTitle('/natal', 'Segundo');
    }

    public function test_an_old_published_page_without_draft_seo_behaves_as_before(): void
    {
        // Como as paginas que ja estao em producao: draft_seo a null.
        $page = app(Pages::class)->system('about');
        $this->assertNull($page->fresh()->getAttributes()['draft_seo'] ?? null);
        $opened = $page->draftVersion();

        $this->assertEditorSeo($page, 'Sobre nós', hasChanges: false);

        // O editor grava sempre o SEO que mostra: igual ao publicado, nao
        // conta como alteracao nem muda o marcador.
        $this->autosave($page, (array) $page->seo);

        $this->assertSame($opened, $page->fresh()->draftVersion());
        $this->assertEditorSeo($page, 'Sobre nós', hasChanges: false);
        $this->assertStoreTitle('/sobre', 'Sobre nós');
    }

    public function test_a_never_published_landing_keeps_its_seo(): void
    {
        $page = $this->service()->createLanding(['name' => 'Natal', 'slug' => 'natal']);
        // O MCP (traducoes) grava so os blocos.
        $this->service()->saveDraft($page, [$this->banner('Feliz Natal')]);

        $this->assertEditorSeo($page, 'Natal', hasChanges: true);

        $this->service()->publish($page->fresh());
        $this->assertStoreTitle('/natal', 'Natal');
    }

    public function test_duplicating_takes_the_seo_the_owner_is_seeing(): void
    {
        $page = $this->publishedLanding('Natal no ar');
        $this->autosave($page, 'Natal por publicar');

        $copy = $this->service()->duplicate($page->fresh());

        $this->assertEditorSeo($copy, 'Natal por publicar', hasChanges: true);
    }
}
