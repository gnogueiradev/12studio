<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Read\PageGetTool;
use App\Mcp\Tools\Read\PagesListTool;
use App\Mcp\Tools\Read\TranslationsMissingTool;
use App\Mcp\Tools\Write\Catalog\ColorUpdateTool;
use App\Mcp\Tools\Write\Catalog\MaterialUpdateTool;
use App\Mcp\Tools\Write\Translations\CategoryTranslationSetTool;
use App\Mcp\Tools\Write\Translations\PageContentTranslateTool;
use App\Mcp\Tools\Write\Translations\ProductTranslationSetTool;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\PageBuilder\PageBuilderService;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * "Claude, traduz o catalogo e a landing do Natal para ES, EN e FR": as
 * ferramentas que o tornam possivel.
 */
class TranslationToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function reader(): PendingTestResponse
    {
        Passport::actingAs($this->admin, ['mcp:read']);

        return StudioServer::actingAs($this->admin);
    }

    private function writer(): PendingTestResponse
    {
        Passport::actingAs($this->admin, ['mcp:read', 'mcp:write']);

        return StudioServer::actingAs($this->admin);
    }

    public function test_product_translation_set_saves_and_sanitizes(): void
    {
        $product = Product::factory()->create(['name' => 'Vaso Onda', 'personalization_fields' => [['key' => 'nome', 'label' => 'Nome']]]);

        $this->writer()->tool(ProductTranslationSetTool::class, [
            'id' => $product->id,
            'locale' => 'en',
            'name' => 'Wave Vase',
            'description' => '<p>A vase.</p><script>x</script>',
            'personalization_labels' => ['nome' => 'Name'],
        ])->assertOk()->assertSee('Wave Vase');

        $translation = $product->translations()->where('locale', 'en')->sole();
        $this->assertSame('Wave Vase', $translation->name);
        $this->assertStringNotContainsString('script', (string) $translation->description);
        $this->assertSame('Name', $product->fresh()->personalization_fields[0]['label_i18n']['en']);
    }

    public function test_the_default_language_and_unknown_languages_are_refused(): void
    {
        $product = Product::factory()->create();

        $this->writer()->tool(ProductTranslationSetTool::class, ['id' => $product->id, 'locale' => 'pt', 'name' => 'X'])->assertHasErrors();
        $this->writer()->tool(ProductTranslationSetTool::class, ['id' => $product->id, 'locale' => 'de', 'name' => 'X'])->assertHasErrors();
        $this->assertSame(0, $product->translations()->count());
    }

    public function test_translation_writes_need_a_write_key(): void
    {
        $this->reader()->tool(ProductTranslationSetTool::class, ['id' => 1, 'locale' => 'en'])->assertNotRegistered();
    }

    public function test_category_translation_set(): void
    {
        $category = Category::factory()->create(['name' => 'Vasos']);

        $this->writer()->tool(CategoryTranslationSetTool::class, ['id' => $category->id, 'locale' => 'fr', 'name' => 'Pots'])->assertOk();

        $this->assertSame('Pots', $category->translations()->where('locale', 'fr')->sole()->name);
    }

    public function test_translations_missing_lists_missing_and_outdated(): void
    {
        $done = Product::factory()->create(['name' => 'Feito']);
        $old = Product::factory()->create(['name' => 'Antigo']);
        Product::factory()->create(['name' => 'Por traduzir']);
        Product::factory()->create(['name' => 'Rascunho', 'status' => 'draft']);
        $service = app(TranslationService::class);

        foreach (['es', 'en', 'fr'] as $locale) {
            $service->setProduct($done, $locale, ['name' => "Feito {$locale}"]);
            $service->setProduct($old, $locale, ['name' => "Antigo {$locale}"]);
        }
        $old->update(['name' => 'Antigo mudado']);

        $this->reader()->tool(TranslationsMissingTool::class, ['type' => 'products'])
            ->assertOk()
            ->assertSee('Por traduzir')
            ->assertSee('Antigo mudado')
            ->assertSee('outdated')
            ->assertDontSee('Feito');
    }

    public function test_pages_can_be_read_and_translated(): void
    {
        $service = app(PageBuilderService::class);
        $page = $service->createLanding(['name' => 'Natal', 'slug' => 'natal']);
        $service->saveDraft($page, [['id' => 'b1', 'type' => 'banner', 'content' => ['pt' => ['title' => 'Feliz Natal', 'text' => 'Encomenda já']]]]);

        $this->reader()->tool(PagesListTool::class)->assertOk()->assertSee('natal');
        $this->reader()->tool(PageGetTool::class, ['id' => $page->id])->assertOk()->assertSee('Feliz Natal')->assertSee('b1');

        $this->writer()->tool(PageContentTranslateTool::class, [
            'id' => $page->id,
            'locale' => 'en',
            'blocks' => [['id' => 'b1', 'content' => ['title' => 'Merry Christmas', 'text' => 'Order now']]],
        ])->assertOk();

        $page->refresh();
        $this->assertSame('Merry Christmas', $page->draft_blocks[0]['content']['en']['title']);
        $this->assertSame('Feliz Natal', $page->draft_blocks[0]['content']['pt']['title']);
        // Traduzir grava o rascunho; o site so muda quando se publica.
        $this->assertNull($page->published_blocks);

        $this->writer()->tool(PageContentTranslateTool::class, [
            'id' => $page->id,
            'locale' => 'en',
            'blocks' => [['id' => 'nao-existe', 'content' => ['title' => 'X']]],
        ])->assertHasErrors();
    }

    public function test_colour_and_material_names_are_translated_through_their_update_tools(): void
    {
        $color = Color::factory()->create(['name' => 'Azul']);
        $material = Material::factory()->create(['name' => 'Madeira']);

        $this->writer()->tool(ColorUpdateTool::class, ['id' => $color->id, 'name_i18n' => ['en' => 'Blue', 'fr' => 'Bleu']])->assertOk();
        $this->writer()->tool(MaterialUpdateTool::class, ['id' => $material->id, 'name_i18n' => ['es' => 'Madera']])->assertOk();

        $this->assertSame(['en' => 'Blue', 'fr' => 'Bleu'], $color->fresh()->name_i18n);
        $this->assertSame(['es' => 'Madera'], $material->fresh()->name_i18n);
    }

    public function test_translations_missing_includes_pages(): void
    {
        $service = app(PageBuilderService::class);
        $page = $service->createLanding(['name' => 'Natal', 'slug' => 'natal']);
        $service->saveDraft($page, [['type' => 'banner', 'content' => ['pt' => ['title' => 'Feliz Natal']]]]);

        $this->reader()->tool(TranslationsMissingTool::class, ['type' => 'pages'])->assertOk()->assertSee('Natal');
    }
}
