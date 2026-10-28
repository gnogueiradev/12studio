<?php

namespace Tests\Feature\PageBuilder;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Variant;
use App\PageBuilder\BlockSanitizer;
use App\PageBuilder\DefaultPages;
use App\PageBuilder\PageRenderer;
use App\PageBuilder\RenderContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PageRendererTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array<string, mixed>>
     */
    private function render(array $blocks, string $locale = 'pt'): array
    {
        $clean = app(BlockSanitizer::class)->clean($blocks, 'page');

        return app(PageRenderer::class)->render($clean, new RenderContext($locale, 'pt'));
    }

    public function test_hidden_blocks_are_left_out(): void
    {
        $rendered = $this->render([
            ['type' => 'spacer'],
            ['type' => 'spacer', 'visible' => false],
        ]);

        $this->assertCount(1, $rendered);
    }

    public function test_unknown_types_are_skipped_without_breaking_the_page(): void
    {
        $rendered = app(PageRenderer::class)->render([
            ['id' => 'a', 'type' => 'bloco-que-ja-nao-existe'],
            ['id' => 'b', 'type' => 'spacer', 'settings' => []],
        ], new RenderContext('pt', 'pt'));

        $this->assertSame(['spacer'], array_column($rendered, 'type'));
    }

    public function test_each_field_falls_back_to_the_default_language(): void
    {
        $blocks = [[
            'type' => 'banner',
            'content' => [
                'pt' => ['title' => 'Olá', 'text' => 'Texto PT', 'link' => ['label' => 'Ver', 'href' => '/produtos']],
                'en' => ['title' => 'Hello'],
            ],
        ]];

        $data = $this->render($blocks, 'en')[0]['data'];

        $this->assertSame('Hello', $data['title']);
        $this->assertSame('Texto PT', $data['text']);
        $this->assertSame(['label' => 'Ver', 'href' => '/en/produtos', 'external' => false], $data['link']);
    }

    public function test_external_links_are_marked(): void
    {
        $data = $this->render([[
            'type' => 'banner',
            'content' => ['pt' => ['title' => 'Etsy', 'link' => ['label' => 'Etsy', 'href' => 'https://www.etsy.com/shop/x']]],
        ]], 'fr')[0]['data'];

        $this->assertSame(['label' => 'Etsy', 'href' => 'https://www.etsy.com/shop/x', 'external' => true], $data['link']);
    }

    public function test_product_grids_only_show_visible_products_and_no_internals(): void
    {
        $category = Category::factory()->create();
        $color = Color::factory()->create(['is_active' => true]);

        $visible = Product::factory()->create(['name' => 'Visível', 'featured' => true, 'category_id' => $category->id]);
        Variant::factory()->for($visible)->isDefault()->create(['color_id' => $color->id, 'price_cents' => 1500, 'stock' => 3]);

        $noVariants = Product::factory()->create(['featured' => true]);
        $inactiveVariant = Product::factory()->create(['featured' => true]);
        Variant::factory()->for($inactiveVariant)->create(['active' => false]);
        $archivedCategory = Product::factory()->create(['featured' => true, 'category_id' => Category::factory()->archived()->create()->id]);
        Variant::factory()->for($archivedCategory)->create();
        $deleted = Product::factory()->create(['featured' => true]);
        Variant::factory()->for($deleted)->create();
        $deleted->delete();

        $data = $this->render([['type' => 'product_grid', 'settings' => ['source' => 'featured']]])[0]['data'];

        $this->assertCount(1, $data['products']);
        $card = $data['products'][0];
        $this->assertSame('Visível', $card['name']);
        $this->assertSame('/produtos/'.$visible->slug, $card['href']);
        $this->assertSame(1500, $card['price']['from']);
        $this->assertSame('ready', $card['availability']);

        // Nada de custos, stock exato, gramagem ou tempos na montra.
        $keys = implode(' ', array_keys(Arr::dot($card)));
        foreach (['stock', 'cost', 'wholesale', 'filament', 'printing', 'labor', 'sku'] as $internal) {
            $this->assertStringNotContainsString($internal, $keys);
        }
    }

    public function test_manual_grids_keep_the_chosen_order(): void
    {
        $a = Product::factory()->create(['name' => 'A']);
        Variant::factory()->for($a)->create();
        $b = Product::factory()->create(['name' => 'B']);
        Variant::factory()->for($b)->create();

        $data = $this->render([['type' => 'product_grid', 'settings' => ['source' => 'manual', 'products' => [$b->id, $a->id]]]])[0]['data'];

        $this->assertSame(['B', 'A'], array_column($data['products'], 'name'));
    }

    public function test_a_full_home_page_does_not_query_per_product(): void
    {
        foreach (range(1, 8) as $i) {
            $product = Product::factory()->create(['featured' => true]);
            Variant::factory()->for($product)->count(3)->create();
        }

        $blocks = DefaultPages::blocks('home');

        DB::enableQueryLog();
        $this->render($blocks);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Com 8 produtos e 24 variantes: um numero fixo, que nao cresce com
        // o catalogo (eager loading em vez de uma query por produto).
        $this->assertLessThan(25, $queries);
    }
}
