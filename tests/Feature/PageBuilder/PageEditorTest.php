<?php

namespace Tests\Feature\PageBuilder;

use App\Models\Media;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\PageBuilder\PageBuilderService;
use App\PageBuilder\Pages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * O editor de paginas do backoffice (/admin/montra/paginas): lista, landings,
 * rascunho com autosave, publicar, historico e a pre-visualizacao em iframe.
 */
class PageEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
        $this->admin = User::factory()->admin()->create();
    }

    private function landing(string $slug = 'natal'): Page
    {
        return app(PageBuilderService::class)->createLanding(['name' => 'Natal', 'slug' => $slug]);
    }

    private function product(string $name): Product
    {
        $product = Product::factory()->create(['name' => $name]);
        Variant::factory()->for($product)->isDefault()->create();

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function banner(string $title, string $locale = 'pt'): array
    {
        return ['type' => 'banner', 'content' => [$locale => ['title' => $title]]];
    }

    public function test_the_page_list_has_every_system_page_and_the_landings(): void
    {
        $this->landing();

        $this->actingAs($this->admin)
            ->get(route('admin.montra.paginas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/montra/paginas')
                ->has('system', count(Page::SYSTEM))
                ->has('landings', 1)
                ->where('landings.0.slug', 'natal')
                ->where('landings.0.status', 'draft'));
    }

    public function test_only_admins_reach_the_editor(): void
    {
        $page = $this->landing();

        $this->get(route('admin.montra.paginas.index'))->assertRedirect();
        $this->actingAs(User::factory()->create())
            ->get(route('admin.montra.paginas.edit', $page))
            ->assertRedirect(route('login'));
        $this->actingAs(User::factory()->production()->create())
            ->putJson(route('admin.montra.paginas.rascunho', $page), ['blocks' => []])
            ->assertForbidden();
    }

    public function test_a_landing_is_created_with_a_free_slug(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.montra.paginas.store'), ['name' => 'Natal 2026', 'slug' => 'natal-2026'])
            ->assertRedirect();

        $page = Page::query()->where('slug', 'natal-2026')->firstOrFail();
        $this->assertSame('draft', $page->status);

        $this->actingAs($this->admin)
            ->post(route('admin.montra.paginas.store'), ['name' => 'Outra', 'slug' => 'produtos'])
            ->assertSessionHasErrors('slug');
    }

    public function test_the_editor_gets_the_page_the_schema_and_the_history(): void
    {
        $page = app(Pages::class)->system('home');

        $this->actingAs($this->admin)
            ->get(route('admin.montra.paginas.edit', $page))
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('admin/montra/editor')
                ->where('page.id', $page->id)
                ->where('page.template', 'page')
                ->has('page.blocks')
                ->has('schema')
                ->has('container.styleProps')
                ->has('container.advancedFields')
                ->where('schema', fn ($schema) => collect($schema)->pluck('type')->contains('hero')
                    && ! collect($schema)->pluck('type')->contains('product_main'))
                ->has('revisions', 1)
                ->where('locales', ['pt', 'es', 'en', 'fr'])
                ->has('previewUrl'));
    }

    public function test_the_pickers_and_revisions_look_at_the_whole_tree(): void
    {
        $product = $this->product('Vaso');
        $background = Media::factory()->create();
        $photo = Media::factory()->create();
        $page = $this->landing();

        app(PageBuilderService::class)->saveDraft($page, [[
            'type' => 'container',
            'style' => ['bg_image' => ['desktop' => $background->id]],
            'children' => [
                ['type' => 'container', 'children' => [
                    ['type' => 'product_grid', 'settings' => ['source' => 'manual', 'products' => [$product->id]]],
                    ['type' => 'text_image', 'settings' => ['image' => $photo->id]],
                ]],
            ],
        ]]);
        app(PageBuilderService::class)->publish($page);

        $this->actingAs($this->admin)
            ->get(route('admin.montra.paginas.edit', $page))
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->has('pickedProducts', 1)
                ->where('pickedProducts.0.id', $product->id)
                ->has("media.{$background->id}")
                ->has("media.{$photo->id}")
                // Um contentor de raiz com outro dentro, mas dois elementos: a lista conta elementos.
                ->where('revisions.0.blocks', 2));
    }

    public function test_autosave_keeps_a_draft_and_the_public_page_does_not_change(): void
    {
        $page = $this->landing();
        app(PageBuilderService::class)->saveDraft($page, [$this->banner('Antes')]);
        app(PageBuilderService::class)->publish($page);

        $this->actingAs($this->admin)
            ->putJson(route('admin.montra.paginas.rascunho', $page), [
                'blocks' => [$this->banner('Depois')],
                'seo' => ['pt' => ['title' => 'Natal']],
                'version' => $page->fresh()->draftVersion(),
            ])
            ->assertOk()
            ->assertJsonPath('page.hasChanges', true);

        $this->assertSame('Depois', $page->fresh()->draft_blocks[0]['children'][0]['content']['pt']['title']);

        auth()->logout();
        $this->get('/natal')->assertInertia(fn (Assert $inertia) => $inertia
            ->where('page.blocks.0.children.0.data.title', 'Antes'));
    }

    /** O marcador da versao do rascunho, tal como o editor o recebe ao abrir. */
    private function editorVersion(Page $page): string
    {
        $version = null;

        $this->actingAs($this->admin)
            ->get(route('admin.montra.paginas.edit', $page))
            ->assertOk()
            // Uma closure normal: a arrow function copiava o $version.
            ->assertInertia(function (Assert $inertia) use (&$version): void {
                $inertia->where('page.version', function (mixed $value) use (&$version): bool {
                    $version = $value;

                    return is_string($value) && $value !== '';
                });
            });

        $this->assertIsString($version);

        return $version;
    }

    public function test_a_save_from_a_stale_editor_is_refused_and_the_draft_stays(): void
    {
        $page = $this->landing();
        app(PageBuilderService::class)->saveDraft($page, [$this->banner('Antes')]);
        $stale = $this->editorVersion($page);

        // Outro separador (ou outra pessoa) grava entretanto.
        app(PageBuilderService::class)->saveDraft($page->fresh(), [$this->banner('Do outro separador')]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.montra.paginas.rascunho', $page), [
                'blocks' => [$this->banner('Velho')],
                'version' => $stale,
            ])
            ->assertConflict()
            ->assertJsonPath('conflict', true)
            ->assertJsonStructure(['message']);

        $this->assertSame('Do outro separador', $page->fresh()->draft_blocks[0]['children'][0]['content']['pt']['title']);
    }

    public function test_a_seo_change_elsewhere_also_makes_the_editor_stale(): void
    {
        $page = $this->landing();
        $stale = $this->editorVersion($page);

        app(PageBuilderService::class)->saveDraft($page->fresh(), [], ['pt' => ['title' => 'Novo título']]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.montra.paginas.rascunho', $page), [
                'blocks' => [$this->banner('Velho')],
                'version' => $stale,
            ])
            ->assertConflict();

        $this->assertSame([], $page->fresh()->draft_blocks);
    }

    public function test_the_current_version_saves_and_two_saves_in_a_row_work(): void
    {
        $page = $this->landing();
        $opened = $this->editorVersion($page);

        $first = $this->actingAs($this->admin)
            ->putJson(route('admin.montra.paginas.rascunho', $page), [
                'blocks' => [$this->banner('Primeira')],
                'version' => $opened,
            ])
            ->assertOk()
            ->json('page.version');

        $this->assertIsString($first);
        $this->assertNotSame($opened, $first);

        // A gravacao seguinte da mesma sessao vai com o marcador da resposta.
        $second = $this->actingAs($this->admin)
            ->putJson(route('admin.montra.paginas.rascunho', $page), [
                'blocks' => [$this->banner('Segunda')],
                'version' => $first,
            ])
            ->assertOk()
            ->json('page.version');

        $this->assertSame('Segunda', $page->fresh()->draft_blocks[0]['children'][0]['content']['pt']['title']);
        // O que a resposta diz e o que o editor recebe ao abrir de novo.
        $this->assertSame($second, $this->editorVersion($page));

        // O marcador de quando se abriu ja nao serve.
        $this->actingAs($this->admin)
            ->putJson(route('admin.montra.paginas.rascunho', $page), [
                'blocks' => [$this->banner('Velho')],
                'version' => $opened,
            ])
            ->assertConflict();
    }

    public function test_a_save_without_a_version_is_refused(): void
    {
        $page = $this->landing();
        app(PageBuilderService::class)->saveDraft($page, [$this->banner('Antes')]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.montra.paginas.rascunho', $page), ['blocks' => [$this->banner('Sem marcador')]])
            ->assertConflict();

        $this->assertSame('Antes', $page->fresh()->draft_blocks[0]['children'][0]['content']['pt']['title']);
    }

    public function test_overwriting_on_purpose_saves_over_the_newer_draft(): void
    {
        $page = $this->landing();
        $stale = $this->editorVersion($page);
        app(PageBuilderService::class)->saveDraft($page->fresh(), [$this->banner('Do outro separador')]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.montra.paginas.rascunho', $page), [
                'blocks' => [$this->banner('Daqui')],
                'version' => $stale,
                'force' => true,
            ])
            ->assertOk()
            ->assertJsonPath('page.version', fn (mixed $version) => is_string($version) && $version !== $stale);

        $this->assertSame('Daqui', $page->fresh()->draft_blocks[0]['children'][0]['content']['pt']['title']);
    }

    public function test_restoring_a_revision_gives_the_editor_a_new_version(): void
    {
        $page = $this->landing();
        $service = app(PageBuilderService::class);
        $service->saveDraft($page, [$this->banner('Primeira')]);
        $service->publish($page);
        $service->saveDraft($page->fresh(), [$this->banner('Segunda')]);
        $before = $this->editorVersion($page);

        $this->actingAs($this->admin)
            ->post(route('admin.montra.paginas.repor', [$page, $page->revisions()->first()]))
            ->assertRedirect();

        $after = $this->editorVersion($page);
        $this->assertNotSame($before, $after);

        $this->actingAs($this->admin)
            ->putJson(route('admin.montra.paginas.rascunho', $page), [
                'blocks' => [$this->banner('Depois de repor')],
                'version' => $after,
            ])
            ->assertOk();
    }

    public function test_an_invalid_draft_comes_back_with_the_field_path(): void
    {
        $page = $this->landing();

        $this->actingAs($this->admin)
            ->putJson(route('admin.montra.paginas.rascunho', $page), [
                'blocks' => [['type' => 'banner', 'content' => ['pt' => ['title' => str_repeat('x', 500)]]]],
                'version' => $page->draftVersion(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('blocks.0.children.0.content.pt.title');
    }

    public function test_an_unfinished_block_saves_as_draft_but_does_not_publish(): void
    {
        $page = $this->landing();

        // Um bloco de texto acabado de juntar: o "Texto" e obrigatorio e
        // ainda esta vazio. O rascunho grava; publicar nao.
        $this->actingAs($this->admin)
            ->putJson(route('admin.montra.paginas.rascunho', $page), [
                'blocks' => [['type' => 'text', 'content' => ['pt' => ['body' => '']]]],
                'version' => $page->draftVersion(),
            ])
            ->assertOk();

        $this->assertCount(1, $page->fresh()->draft_blocks);

        $this->actingAs($this->admin)
            ->post(route('admin.montra.paginas.publicar', $page))
            ->assertSessionHasErrors('blocks.0.children.0.content.pt.body');

        $this->assertSame('draft', $page->fresh()->status);
    }

    public function test_publish_then_restore_an_older_version(): void
    {
        $page = $this->landing();
        $service = app(PageBuilderService::class);
        $service->saveDraft($page, [$this->banner('Primeira')]);

        $this->actingAs($this->admin)
            ->post(route('admin.montra.paginas.publicar', $page))
            ->assertRedirect();

        $service->saveDraft($page->fresh(), [$this->banner('Segunda')]);
        $this->actingAs($this->admin)->post(route('admin.montra.paginas.publicar', $page));

        $first = $page->revisions()->get()->last();

        $this->actingAs($this->admin)
            ->post(route('admin.montra.paginas.repor', [$page, $first]))
            ->assertRedirect();

        $page->refresh();
        $this->assertSame('Primeira', $page->draft_blocks[0]['children'][0]['content']['pt']['title']);
        $this->assertSame('Segunda', $page->published_blocks[0]['children'][0]['content']['pt']['title']);
    }

    public function test_a_revision_of_another_page_is_refused(): void
    {
        $a = $this->landing('a');
        $b = $this->landing('b');
        app(PageBuilderService::class)->publish($b);

        $this->actingAs($this->admin)
            ->post(route('admin.montra.paginas.repor', [$a, $b->revisions()->first()]))
            ->assertNotFound();
    }

    public function test_landing_settings_duplicate_unpublish_and_delete(): void
    {
        $page = $this->landing();

        $this->actingAs($this->admin)
            ->patch(route('admin.montra.paginas.definicoes', $page), [
                'name' => 'Natal',
                'slug' => 'natal-2026',
                'starts_at' => '2026-12-01T00:00',
                'ends_at' => '2026-12-26T00:00',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $page->refresh();
        $this->assertSame('natal-2026', $page->slug);
        $this->assertSame('2026-12-01', $page->starts_at?->toDateString());

        $this->actingAs($this->admin)
            ->patch(route('admin.montra.paginas.definicoes', $page), [
                'name' => 'Natal',
                'slug' => 'natal-2026',
                'starts_at' => '2026-12-26T00:00',
                'ends_at' => '2026-12-01T00:00',
            ])
            ->assertSessionHasErrors('ends_at');

        $this->actingAs($this->admin)->post(route('admin.montra.paginas.duplicar', $page))->assertRedirect();
        $this->assertTrue(Page::query()->where('slug', 'natal-2026-copia')->exists());

        app(PageBuilderService::class)->publish($page);
        $this->actingAs($this->admin)->post(route('admin.montra.paginas.despublicar', $page))->assertRedirect();
        $this->assertSame('unpublished', $page->fresh()->status);

        $this->actingAs($this->admin)->delete(route('admin.montra.paginas.destroy', $page))->assertRedirect();
        $this->assertNull($page->fresh());
    }

    public function test_system_pages_are_never_deleted(): void
    {
        $page = app(Pages::class)->system('about');

        $this->actingAs($this->admin)
            ->delete(route('admin.montra.paginas.destroy', $page))
            ->assertSessionHasErrors('page');

        $this->assertNotNull($page->fresh());
    }

    public function test_a_required_template_block_cannot_be_removed(): void
    {
        $page = app(Pages::class)->system('product');

        $this->actingAs($this->admin)
            ->putJson(route('admin.montra.paginas.rascunho', $page), ['blocks' => [], 'version' => $page->draftVersion()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('blocks');
    }

    public function test_preview_render_cleans_rich_text_without_saving(): void
    {
        $page = $this->landing();

        $this->actingAs($this->admin)
            ->postJson(route('admin.montra.paginas.render', $page), [
                'locale' => 'en',
                'blocks' => [
                    ['id' => 'a', 'type' => 'text', 'content' => ['pt' => ['body' => '<p>Olá</p><script>alert(1)</script>'], 'en' => ['body' => '<p>Hello</p><img src=x onerror=alert(1)>']]],
                    // Um campo obrigatorio vazio ou um texto comprido demais
                    // nao impedem a pre-visualizacao: so a gravacao.
                    ['id' => 'b', 'type' => 'banner', 'content' => ['pt' => ['title' => str_repeat('y', 300)]]],
                    ['id' => 'c', 'type' => 'nao-existe'],
                ],
            ])
            ->assertOk()
            // O bloco desconhecido sai, deixando a sua seccao vazia.
            ->assertJsonCount(3, 'blocks')
            ->assertJsonCount(0, 'blocks.2.children')
            ->assertJsonPath('blocks.0.children.0.id', 'a')
            ->assertJsonPath('blocks.0.children.0.data.body', fn (string $html) => str_contains($html, 'Hello')
                && ! str_contains($html, 'onerror') && ! str_contains($html, '<script'));

        $this->assertSame([], $page->fresh()->draft_blocks);
    }

    public function test_the_preview_frame_is_admin_only_and_the_only_framable_page(): void
    {
        $page = $this->landing();
        app(PageBuilderService::class)->saveDraft($page, [$this->banner('Rascunho')]);

        $this->get(route('admin.montra.preview', $page))->assertRedirect();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.montra.preview', ['page' => $page, 'locale' => 'en']))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('store/page')
                ->where('page.preview', true)
                ->where('page.blocks.0.children.0.data.title', 'Rascunho')
                ->where('locale.current', 'en'));

        $this->assertStringContainsString("frame-ancestors 'self'", (string) $response->headers->get('Content-Security-Policy-Report-Only'));

        $this->get('/')->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $this->get('/')->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_the_product_template_previews_with_a_real_product(): void
    {
        $this->product('Vaso Onda');
        $page = app(Pages::class)->system('product');

        $this->actingAs($this->admin)
            ->get(route('admin.montra.preview', $page))
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('store/page')
                ->where('page.preview', true)
                ->where('product.name', 'Vaso Onda'));
    }

    public function test_the_catalog_template_previews_the_listing(): void
    {
        $page = app(Pages::class)->system('catalog');

        $this->actingAs($this->admin)
            ->get(route('admin.montra.preview', $page))
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('page.preview', true)
                ->has('catalog'));
    }

    public function test_product_search_for_the_picker(): void
    {
        $this->product('Vaso Onda');
        $this->product('Porta-chaves');

        $this->actingAs($this->admin)
            ->getJson(route('admin.montra.opcoes.produtos', ['q' => 'vaso']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Vaso Onda');
    }
}
