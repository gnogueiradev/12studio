<?php

namespace Tests\Feature\Store;

use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
    }

    private function product(string $name, array $attributes = [], array $variant = []): Product
    {
        $product = Product::factory()->create(['name' => $name] + $attributes);
        Variant::factory()->for($product)->isDefault()->create($variant + ['price_cents' => 1000, 'stock' => 2]);

        return $product;
    }

    /**
     * @return array<int, string>
     */
    private function names(string $url): array
    {
        $names = [];

        $this->get($url)->assertOk()->assertInertia(function (Assert $page) use (&$names): void {
            $page->component('store/page');
            $names = array_column($page->toArray()['props']['products']['data'], 'name');
        });

        return $names;
    }

    public function test_the_catalog_lists_only_what_is_on_sale(): void
    {
        $this->product('À venda');
        $this->product('Rascunho', ['status' => 'draft']);
        Product::factory()->create(['name' => 'Sem variantes']);

        $this->assertSame(['À venda'], $this->names('/produtos'));
    }

    public function test_search_ignores_accents_and_case(): void
    {
        $this->product('Peça Decorativa');
        $this->product('Vaso Grande', ['description' => '<p>Para uma <strong>peça</strong> central</p>']);
        $this->product('Candeeiro');

        $this->assertEqualsCanonicalizing(['Peça Decorativa', 'Vaso Grande'], $this->names('/produtos?q=PECA'));
        $this->assertSame(['Peça Decorativa'], $this->names('/produtos?q=peça decorativa'));
        $this->assertSame([], $this->names('/produtos?q=100%25'));
    }

    public function test_filters_by_colour_material_price_and_availability(): void
    {
        $blue = Color::factory()->create(['is_active' => true]);
        $red = Color::factory()->create(['is_active' => true]);
        $pla = Material::factory()->create();

        $this->product('Azul', [], ['color_id' => $blue->id, 'price_cents' => 1500]);
        $this->product('Vermelho PLA', [], ['color_id' => $red->id, 'material_id' => $pla->id, 'price_cents' => 4000, 'stock' => 0]);
        $this->product('Por encomenda', ['fulfillment_mode' => 'made_to_order'], ['price_cents' => 2500, 'stock' => 0]);

        $this->assertSame(['Azul'], $this->names("/produtos?cor={$blue->id}"));
        $this->assertEqualsCanonicalizing(['Azul', 'Vermelho PLA'], $this->names("/produtos?cor={$blue->id},{$red->id}"));
        $this->assertSame(['Vermelho PLA'], $this->names("/produtos?material={$pla->id}"));
        $this->assertEqualsCanonicalizing(['Azul', 'Por encomenda'], $this->names('/produtos?preco_max=30'));
        $this->assertSame(['Vermelho PLA'], $this->names('/produtos?preco_min=30'));
        $this->assertSame(['Azul'], $this->names('/produtos?disponibilidade=pronto'));
        $this->assertSame(['Por encomenda'], $this->names('/produtos?disponibilidade=encomenda'));
    }

    public function test_nonsense_filters_are_ignored(): void
    {
        $this->product('Um');

        $this->assertSame(['Um'], $this->names('/produtos?cor=abc&preco_min=-5&disponibilidade=amanha&ordem=aleatoria'));
    }

    public function test_sorting_by_price(): void
    {
        $this->product('Médio', [], ['price_cents' => 2000]);
        $this->product('Barato', [], ['price_cents' => 500]);
        $this->product('Caro', [], ['price_cents' => 9000]);

        $this->assertSame(['Barato', 'Médio', 'Caro'], $this->names('/produtos?ordem=preco-asc'));
        $this->assertSame(['Caro', 'Médio', 'Barato'], $this->names('/produtos?ordem=preco-desc'));
    }

    public function test_featured_products_follow_their_manual_order(): void
    {
        $this->product('Segundo', ['featured' => true, 'sort_order' => 2]);
        $this->product('Normal');
        $this->product('Primeiro', ['featured' => true, 'sort_order' => 1]);

        $this->assertSame(['Primeiro', 'Segundo', 'Normal'], $this->names('/produtos'));
    }

    public function test_pages_of_24_for_load_more(): void
    {
        foreach (range(1, 30) as $i) {
            $this->product("P{$i}");
        }

        $this->get('/produtos')->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 24)
            ->where('catalog.total', 30));

        $this->assertCount(6, $this->names('/produtos?page=2'));
    }

    public function test_a_category_page_shows_its_products_and_header(): void
    {
        $vasos = Category::factory()->create(['name' => 'Vasos', 'slug' => 'vasos', 'description' => 'Para plantas.']);
        $this->product('Vaso', ['category_id' => $vasos->id]);
        $this->product('Candeeiro');

        $this->assertSame(['Vaso'], $this->names('/categoria/vasos'));

        $this->get('/en/categoria/vasos')->assertInertia(fn (Assert $page) => $page
            ->where('page.title', 'Vasos')
            ->where('page.blocks.0.type', 'catalog_header')
            ->where('page.blocks.0.data.title', 'Vasos')
            ->where('catalog.path', '/en/categoria/vasos'));
    }

    public function test_hidden_categories_open_by_link_and_archived_ones_do_not(): void
    {
        Category::factory()->hidden()->create(['slug' => 'secreta']);
        Category::factory()->archived()->create(['slug' => 'velha']);

        $this->get('/categoria/secreta')->assertOk();
        $this->get('/categoria/velha')->assertNotFound();
        $this->get('/categoria/nao-existe')->assertNotFound();
    }

    public function test_facets_only_offer_what_is_for_sale(): void
    {
        $used = Color::factory()->create(['is_active' => true, 'name' => 'Usada']);
        Color::factory()->create(['is_active' => true, 'name' => 'Sem produtos']);
        $this->product('A', [], ['color_id' => $used->id, 'price_cents' => 1250]);
        $this->product('B', [], ['price_cents' => 4999]);

        $this->get('/produtos')->assertInertia(fn (Assert $page) => $page
            ->where('catalog.facets.colors', [['id' => $used->id, 'name' => 'Usada', 'hex' => $used->hex_color]])
            ->where('catalog.facets.price', ['min' => 12, 'max' => 50]));
    }

    public function test_filtered_listings_are_not_indexed(): void
    {
        $this->get('/produtos')->assertDontSee('name="robots"', false)
            ->assertSee('<link rel="canonical" href="'.url('/produtos').'">', false);

        $this->get('/produtos?q=vaso')->assertSee('<meta name="robots" content="noindex">', false);
    }
}
