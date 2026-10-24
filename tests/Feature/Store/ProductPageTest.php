<?php

namespace Tests\Feature\Store;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
    }

    private function vaso(array $attributes = []): Product
    {
        $category = Category::factory()->create(['name' => 'Vasos', 'slug' => 'vasos']);
        $product = Product::factory()->create([
            'name' => 'Vaso Ondas',
            'slug' => 'vaso-ondas',
            'category_id' => $category->id,
            'description' => '<p>Um vaso.</p>',
            'production_time_days' => 5,
        ] + $attributes);

        $blue = Color::factory()->create(['is_active' => true, 'name' => 'Azul']);
        $red = Color::factory()->create(['is_active' => true, 'name' => 'Vermelho']);

        Variant::factory()->for($product)->isDefault()->create(['color_id' => $blue->id, 'price_cents' => 1500, 'stock' => 3, 'length_mm' => 120]);
        Variant::factory()->for($product)->create(['color_id' => $red->id, 'price_cents' => 1800, 'stock' => 0]);
        Variant::factory()->for($product)->create(['active' => false]);

        return $product;
    }

    public function test_the_product_page_renders_the_template_with_the_product(): void
    {
        $this->vaso();

        $this->get('/produtos/vaso-ondas')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('store/page')
            ->where('page.key', 'product')
            ->where('page.title', 'Vaso Ondas')
            ->where('page.blocks.0.type', 'breadcrumb')
            ->where('page.blocks.0.data.items.1.label', 'Vasos')
            ->where('page.blocks.1.type', 'product_main')
            ->where('product.name', 'Vaso Ondas')
            ->where('product.price', ['from' => 1500, 'to' => 1800, 'compareAt' => null, 'varies' => true])
            ->where('product.availability', 'ready')
            ->has('product.variants', 2)
            ->has('product.colors', 2)
            ->where('product.variants.0.ready', true)
            ->where('product.variants.1.ready', false)
            ->where('product.variants.0.specs', ['length' => 120])
            ->where('product.preview', false));
    }

    public function test_no_internal_data_reaches_the_product_page(): void
    {
        $this->vaso();

        $this->get('/produtos/vaso-ondas')->assertInertia(function (Assert $page): void {
            $keys = implode(' ', array_keys(Arr::dot($page->toArray()['props']['product'])));

            foreach (['stock', 'cost', 'wholesale', 'filament', 'printing', 'labor', 'sku', 'vat'] as $internal) {
                $this->assertStringNotContainsString($internal, $keys);
            }
        });
    }

    public function test_products_not_on_sale_are_only_previewed_by_the_admin(): void
    {
        $this->vaso(['status' => 'draft']);

        $this->get('/produtos/vaso-ondas')->assertNotFound();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/produtos/vaso-ondas')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex">', false)
            ->assertInertia(fn (Assert $page) => $page->where('product.preview', true));
    }

    public function test_links_follow_the_language(): void
    {
        $this->vaso();

        $this->get('/fr/produtos/vaso-ondas')->assertInertia(fn (Assert $page) => $page
            ->where('product.href', '/fr/produtos/vaso-ondas')
            ->where('product.category.href', '/fr/categoria/vasos')
            ->where('page.blocks.0.data.items.0', ['label' => 'Catalogue', 'href' => '/fr/produtos']));
    }

    public function test_seo_tags_and_structured_data(): void
    {
        $this->vaso(['seo_description' => 'O vaso mais bonito.']);

        $html = $this->get('/produtos/vaso-ondas')->getContent();

        $this->assertStringContainsString('<meta name="description" content="O vaso mais bonito.">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/produtos/vaso-ondas').'">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="'.url('/en/produtos/vaso-ondas').'">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="'.url('/produtos/vaso-ondas').'">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="product">', $html);
        $this->assertStringContainsString('"@type":"AggregateOffer"', $html);
        $this->assertStringContainsString('"lowPrice":"15.00"', $html);
        $this->assertStringContainsString('"availability":"https://schema.org/InStock"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
    }

    public function test_custom_pieces_have_no_price_in_structured_data(): void
    {
        $this->vaso(['fulfillment_mode' => 'custom']);

        $html = $this->get('/produtos/vaso-ondas')->getContent();

        $this->assertStringContainsString('"@type":"Product"', $html);
        $this->assertStringNotContainsString('AggregateOffer', $html);
    }

    public function test_structured_data_cannot_break_out_of_its_script(): void
    {
        $this->vaso(['description' => '<p>x</p></script><script>alert(1)</script>']);

        $html = $this->get('/produtos/vaso-ondas')->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
    }

    public function test_unknown_products_are_not_found(): void
    {
        $this->get('/produtos/nao-existe')->assertNotFound();
    }
}
