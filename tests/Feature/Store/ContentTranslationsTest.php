<?php

namespace Tests\Feature\Store;

use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Os produtos, as categorias, as cores e os materiais nos outros idiomas: o
 * que estiver traduzido aparece; o que faltar cai no portugues.
 */
class ContentTranslationsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);

        $this->category = Category::factory()->create(['name' => 'Vasos', 'slug' => 'vasos', 'status' => 'visible', 'description' => 'Vasos para plantas']);
        $color = Color::factory()->create(['name' => 'Azul', 'is_active' => true, 'name_i18n' => ['en' => 'Blue']]);
        $material = Material::factory()->create(['name' => 'Madeira', 'name_i18n' => ['en' => 'Wood']]);
        $this->product = Product::factory()->create([
            'name' => 'Vaso Onda',
            'slug' => 'vaso-onda',
            'description' => '<p>Um vaso ondulado.</p>',
            'category_id' => $this->category->id,
            'personalization_fields' => [['key' => 'nome', 'label' => 'Nome a gravar', 'label_i18n' => ['en' => 'Name to engrave']]],
        ]);
        Variant::factory()->for($this->product)->isDefault()->create(['color_id' => $color->id, 'material_id' => $material->id]);
    }

    private function translateProduct(string $locale, array $fields): void
    {
        app(TranslationService::class)->setProduct($this->product, $locale, $fields);
    }

    public function test_a_translated_product_shows_in_its_language_and_falls_back_to_portuguese(): void
    {
        $this->translateProduct('en', ['name' => 'Wave Vase', 'description' => '<p>A wavy vase.</p><script>alert(1)</script>']);

        $this->get('/en/produtos/vaso-onda')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.name', 'Wave Vase')
            ->where('product.description', fn (string $html) => str_contains($html, 'A wavy vase') && ! str_contains($html, 'script'))
            ->where('product.colors.0.name', 'Blue')
            ->where('product.materials.0.name', 'Wood')
            ->where('product.personalization.fields.0.label', 'Name to engrave'));

        $this->get('/fr/produtos/vaso-onda')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.name', 'Vaso Onda')
            ->where('product.colors.0.name', 'Azul'));

        $this->get('/produtos/vaso-onda')->assertInertia(fn (Assert $page) => $page->where('product.name', 'Vaso Onda'));
    }

    public function test_the_seo_title_and_description_follow_the_translation(): void
    {
        $this->translateProduct('en', ['name' => 'Wave Vase', 'seo_title' => 'Wave vase, 3D printed', 'seo_description' => 'A vase with waves.']);

        $this->get('/en/produtos/vaso-onda')
            ->assertSee('<meta property="og:title" content="Wave vase, 3D printed', false)
            ->assertSee('<meta name="description" content="A vase with waves.">', false);
    }

    public function test_catalog_cards_categories_and_filters_are_translated(): void
    {
        $this->translateProduct('en', ['name' => 'Wave Vase']);
        app(TranslationService::class)->setCategory($this->category, 'en', ['name' => 'Planters', 'description' => 'Planters for plants']);

        $this->get('/en/produtos')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.name', 'Wave Vase')
            ->where('products.data.0.category', 'Planters')
            ->where('catalog.facets.categories.0.name', 'Planters')
            ->where('catalog.facets.colors.0.name', 'Blue')
            ->where('catalog.facets.materials.0.name', 'Wood')
            ->where('storefront.categories.0.label', 'Planters'));

        $this->get('/en/categoria/vasos')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('page.title', 'Planters'));
    }

    public function test_search_works_in_the_language_with_portuguese_as_fallback(): void
    {
        $this->translateProduct('es', ['name' => 'Jarrón Ola']);

        $this->get('/es/produtos?q=jarron')->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
        $this->get('/es/produtos?q=onda')->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
        $this->get('/produtos?q=jarron')->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
    }

    public function test_a_translation_goes_out_of_date_when_the_portuguese_changes(): void
    {
        $service = app(TranslationService::class);
        $this->assertSame('missing', $service->productStatus($this->product, 'en'));

        $this->translateProduct('en', ['name' => 'Wave Vase']);
        $this->assertSame('ok', $service->productStatus($this->product->fresh(), 'en'));

        $this->product->update(['name' => 'Vaso Onda Grande']);
        $this->assertSame('outdated', $service->productStatus($this->product->fresh(), 'en'));
    }

    public function test_the_admin_edits_translations_on_the_product_page(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.produtos.edit', $this->product))
            ->assertInertia(fn (Assert $page) => $page
                ->where('translations.en.status', 'missing')
                ->has('translations.es')
                ->has('translations.fr'));

        $this->actingAs($admin)
            ->put(route('admin.produtos.traducoes', [$this->product, 'en']), [
                'name' => 'Wave Vase',
                'description' => '<p>A wavy vase.</p>',
                'seo_title' => '',
                'seo_description' => '',
                'personalization' => ['nome' => 'Name to engrave'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('ok', app(TranslationService::class)->productStatus($this->product->fresh(), 'en'));

        $this->actingAs($admin)
            ->put(route('admin.produtos.traducoes', [$this->product, 'pt']), ['name' => 'X'])
            ->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->put(route('admin.produtos.traducoes', [$this->product, 'en']), ['name' => 'X'])
            ->assertForbidden();
    }

    public function test_the_admin_edits_category_translations(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.categorias.traducoes', [$this->category, 'fr']), ['name' => 'Pots', 'description' => 'Des pots'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->get('/fr/categoria/vasos')->assertInertia(fn (Assert $page) => $page->where('page.title', 'Pots'));
    }

    public function test_category_descriptions_stay_plain_text(): void
    {
        app(TranslationService::class)->setCategory($this->category, 'es', ['name' => 'Llaveros & imanes', 'description' => 'Llaveros & imanes <b>3D</b>']);

        $translation = $this->category->translations()->where('locale', 'es')->sole();
        $this->assertSame('Llaveros & imanes 3D', $translation->description);
    }

    public function test_a_partial_update_does_not_mark_an_outdated_translation_as_up_to_date(): void
    {
        $service = app(TranslationService::class);
        $this->translateProduct('en', ['name' => 'Wave Vase', 'description' => '<p>A vase.</p>']);
        $this->product->update(['description' => '<p>Um vaso novo.</p>']);

        $service->setProduct($this->product->fresh(), 'en', ['seo_title' => 'Wave vase']);

        $this->assertSame('outdated', $service->productStatus($this->product->fresh(), 'en'));
    }

    public function test_colour_and_material_labels_are_edited_in_the_backoffice(): void
    {
        $admin = User::factory()->admin()->create();
        $color = Color::query()->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('admin.cores.update', $color), [
                'name' => 'Azul',
                'hex_color' => $color->hex_color,
                'name_i18n' => ['en' => 'Navy', 'es' => 'Azul marino', 'fr' => ''],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(['es' => 'Azul marino', 'en' => 'Navy'], $color->fresh()->name_i18n);
    }
}
