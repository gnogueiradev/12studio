<?php

namespace Tests\Feature\PageBuilder;

use App\Models\Category;
use App\Models\Color;
use App\Models\Media;
use App\Models\Page;
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

    /**
     * Os elementos da arvore renderizada, em pre-ordem.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function elements(array $nodes): array
    {
        $elements = [];

        foreach ($nodes as $node) {
            if ($node['type'] === 'container') {
                array_push($elements, ...$this->elements($node['children']));
            } else {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * @param  array<int, array<string, mixed>>  $children
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function container(string $id, array $children, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'type' => 'container', 'v' => 2, 'visible' => true,
            'settings' => [], 'content' => [], 'style' => [], 'advanced' => [],
            'children' => $children,
        ], $extra);
    }

    public function test_tree_is_rendered_recursively_with_hidden_nodes_removed(): void
    {
        $rendered = $this->render([
            $this->container('s1', [
                $this->container('col', [
                    ['id' => 'e1', 'type' => 'spacer'],
                    ['id' => 'e2', 'type' => 'spacer', 'visible' => false],
                ], ['label' => 'Coluna', 'style' => ['direction' => ['desktop' => 'row']]]),
                ['id' => 'e3', 'type' => 'spacer', 'shell' => false],
            ]),
            $this->container('s2', [['id' => 'e4', 'type' => 'spacer']], ['visible' => false]),
            $this->container('s3', [['id' => 'e5', 'type' => 'spacer', 'visible' => false]]),
        ]);

        $this->assertSame(['s1', 's3'], array_column($rendered, 'id'));
        $this->assertSame([], $rendered[1]['children']);

        $column = $rendered[0]['children'][0];
        $this->assertSame('container', $column['type']);
        $this->assertSame('Coluna', $column['label']);
        $this->assertSame(['direction' => ['desktop' => 'row']], $column['style']);
        $this->assertNull($column['bgImage']);
        $this->assertSame(['e1'], array_column($column['children'], 'id'));

        $element = $rendered[0]['children'][1];
        $this->assertSame('e3', $element['id']);
        $this->assertFalse($element['shell']);
        $this->assertSame([], $element['styleProps']);
        $this->assertSame([], $element['advanced']);
        $this->assertArrayHasKey('label', $element);
        $this->assertArrayHasKey('variant', $element);
        $this->assertArrayHasKey('data', $element);
    }

    public function test_v1_blocks_render_like_before(): void
    {
        $renderer = app(PageRenderer::class);

        foreach (array_keys(DefaultPages::NAMES) as $key) {
            // Os blocos de origem saem em arvore; o v1 e o filho de cada raiz.
            $v1 = array_map(fn (array $root): array => $root['children'][0], DefaultPages::blocks($key));

            $flat = $this->elements($renderer->render(
                app(BlockSanitizer::class)->preview($v1, Page::TEMPLATES[$key] ?? 'page'),
                new RenderContext('pt', 'pt'),
            ));

            // Referencia: cada bloco v1 sozinho, como a pagina o dava antes
            // de haver arvore.
            $reference = [];
            foreach ($v1 as $block) {
                array_push($reference, ...$this->elements($renderer->render([$block], new RenderContext('pt', 'pt'))));
            }

            // Os blocos de DefaultPages nao tem id; a ordem e os tipos chegam.
            $this->assertSame(array_column($reference, 'type'), array_column($flat, 'type'), $key);

            foreach ($flat as $i => $element) {
                $this->assertSame($reference[$i]['data'], $element['data'], "$key / {$element['type']}");
                $this->assertTrue($element['shell'], "$key / {$element['type']}");
            }
        }
    }

    public function test_container_background_image_is_resolved(): void
    {
        $media = Media::factory()->create();

        $rendered = $this->render([
            $this->container('s1', [['id' => 'e1', 'type' => 'spacer']], [
                'style' => ['bg_image' => ['desktop' => $media->id]],
            ]),
            $this->container('s2', []),
        ]);

        $this->assertSame($media->responsive(''), $rendered[0]['bgImage']);
        $this->assertNull($rendered[1]['bgImage']);

        // Uma so query para todas as imagens da arvore.
        $clean = app(BlockSanitizer::class)->clean([
            $this->container('a', [], ['style' => ['bg_image' => ['desktop' => $media->id]]]),
            $this->container('b', [], ['style' => ['bg_image' => ['desktop' => $media->id]]]),
        ], 'page');

        DB::enableQueryLog();
        app(PageRenderer::class)->render($clean, new RenderContext('pt', 'pt'));
        $mediaQueries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'media'));
        DB::disableQueryLog();

        $this->assertCount(1, $mediaQueries);
    }

    public function test_hidden_blocks_are_left_out(): void
    {
        $rendered = $this->render([
            ['type' => 'spacer'],
            ['type' => 'spacer', 'visible' => false],
        ]);

        $this->assertCount(1, $this->elements($rendered));
    }

    public function test_unknown_types_are_skipped_without_breaking_the_page(): void
    {
        $rendered = app(PageRenderer::class)->render([
            ['id' => 'a', 'type' => 'bloco-que-ja-nao-existe'],
            ['id' => 'b', 'type' => 'spacer', 'settings' => []],
        ], new RenderContext('pt', 'pt'));

        $this->assertSame(['spacer'], array_column($this->elements($rendered), 'type'));
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

        $data = $this->elements($this->render($blocks, 'en'))[0]['data'];

        $this->assertSame('Hello', $data['title']);
        $this->assertSame('Texto PT', $data['text']);
        $this->assertSame(['label' => 'Ver', 'href' => '/en/produtos', 'external' => false], $data['link']);
    }

    public function test_external_links_are_marked(): void
    {
        $data = $this->elements($this->render([[
            'type' => 'banner',
            'content' => ['pt' => ['title' => 'Etsy', 'link' => ['label' => 'Etsy', 'href' => 'https://www.etsy.com/shop/x']]],
        ]], 'fr'))[0]['data'];

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

        $data = $this->elements($this->render([['type' => 'product_grid', 'settings' => ['source' => 'featured']]]))[0]['data'];

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

        $data = $this->elements($this->render([['type' => 'product_grid', 'settings' => ['source' => 'manual', 'products' => [$b->id, $a->id]]]]))[0]['data'];

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
