<?php

namespace Tests\Feature\PageBuilder;

use App\Models\Media;
use App\PageBuilder\BlockSanitizer;
use App\PageBuilder\Container;
use App\PageBuilder\PageDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tudo o que um bloco grava passa por aqui. O que sai e o que a montra
 * renderiza — por isso nada desconhecido, nada de HTML perigoso, nenhum link
 * para fora do que se espera.
 *
 * Os blocos v1 (planos) entram pelo upgrade: cada um sai embrulhado num
 * contentor de raiz, por isso ficam em [i]['children'][0].
 */
class BlockSanitizerTest extends TestCase
{
    use RefreshDatabase;

    private function clean(array $blocks, string $template = 'page'): array
    {
        return app(BlockSanitizer::class)->clean($blocks, $template);
    }

    /**
     * O primeiro elemento de cada contentor de raiz (a forma de um v1).
     *
     * @return array<int, array<string, mixed>>
     */
    private function elements(array $blocks, string $template = 'page'): array
    {
        return array_map(fn (array $root): array => $root['children'][0], $this->clean($blocks, $template));
    }

    /**
     * @return array<string, string>
     */
    private function errors(array $blocks, string $template = 'page'): array
    {
        try {
            $this->clean($blocks, $template);
        } catch (ValidationException $exception) {
            return array_map(fn (array $messages): string => $messages[0], $exception->errors());
        }

        $this->fail('Esperava erros de validacao.');
    }

    private function text(string $body, array $extra = []): array
    {
        return ['type' => 'text', 'content' => ['pt' => ['body' => $body]]] + $extra;
    }

    /**
     * @param  array<int, array<string, mixed>>  $children
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function container(array $children, array $extra = []): array
    {
        return ['type' => 'container', 'children' => $children] + $extra;
    }

    public function test_a_valid_block_comes_out_normalized(): void
    {
        [$root] = $this->clean([$this->text('<p>Olá</p>', ['id' => 'b1'])]);

        $this->assertSame('c-b1', $root['id']);
        $this->assertSame('container', $root['type']);
        $this->assertSame(2, $root['v']);
        $this->assertSame([], $root['style']);
        $this->assertSame([], $root['advanced']);

        $block = $root['children'][0];
        $this->assertSame('b1', $block['id']);
        $this->assertSame('text', $block['type']);
        $this->assertSame('narrow', $block['variant']);
        $this->assertTrue($block['visible']);
        $this->assertNull($block['label']);
        $this->assertTrue($block['shell']);
        $this->assertSame([], $block['styleProps']);
        $this->assertSame([], $block['advanced']);
        $this->assertSame(['bg' => 'default', 'spacing' => 'md', 'width' => 'contained', 'align' => 'start'], $block['style']);
        $this->assertSame(['title' => '', 'body' => '<p>Olá</p>'], $block['content']['pt']);
        $this->assertSame([], $block['content']['en']);
    }

    public function test_a_block_without_id_gets_a_uuid(): void
    {
        [$block] = $this->elements([$this->text('<p>Olá</p>')]);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $block['id']);
    }

    public function test_unknown_types_variants_and_styles(): void
    {
        $this->assertArrayHasKey('blocks.0.children.0.type', $this->errors([['type' => 'iframe']]));

        [$block] = $this->elements([$this->text('<p>x</p>', [
            'variant' => 'gigante',
            'style' => ['bg' => '#ff0000', 'spacing' => 'enorme', 'width' => 'full'],
        ])]);

        $this->assertSame('narrow', $block['variant']);
        $this->assertSame('default', $block['style']['bg']);
        $this->assertSame('md', $block['style']['spacing']);
        $this->assertSame('full', $block['style']['width']);
    }

    public function test_rich_text_is_purified(): void
    {
        [$block] = $this->elements([$this->text('<p onclick="x()">Olá<script>alert(1)</script><img src=x onerror=y> <a href="javascript:alert(1)">link</a></p>')]);

        $html = $block['content']['pt']['body'];
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('Olá', $html);
    }

    public function test_an_empty_editor_counts_as_empty(): void
    {
        $this->assertArrayHasKey('blocks.0.children.0.content.pt.body', $this->errors([$this->text('<p><br></p>')]));
    }

    public function test_required_fields_are_only_required_in_the_default_language(): void
    {
        $errors = $this->errors([['type' => 'banner', 'content' => ['pt' => ['title' => '']]]]);
        $this->assertArrayHasKey('blocks.0.children.0.content.pt.title', $errors);

        [$block] = $this->elements([['type' => 'banner', 'content' => ['pt' => ['title' => 'Olá'], 'en' => ['title' => '']]]]);
        $this->assertSame([], $block['content']['en']);
    }

    public function test_links_only_go_where_they_should(): void
    {
        foreach (['javascript:alert(1)', '//evil.example', '/\\evil.example', 'http://inseguro.pt', 'data:text/html,x', 'ftp://x'] as $href) {
            $errors = $this->errors([['type' => 'banner', 'content' => ['pt' => [
                'title' => 'Olá', 'link' => ['label' => 'Ir', 'href' => $href],
            ]]]]);

            $this->assertArrayHasKey('blocks.0.children.0.content.pt.link.href', $errors, $href);
        }

        foreach (['/produtos', '/produtos?cor=azul', 'https://www.etsy.com/shop/x', 'mailto:ola@loja.pt', 'tel:+351912345678', '#pedido'] as $href) {
            [$block] = $this->elements([['type' => 'banner', 'content' => ['pt' => [
                'title' => 'Olá', 'link' => ['label' => 'Ir', 'href' => $href],
            ]]]]);

            $this->assertSame($href, $block['content']['pt']['link']['href']);
        }
    }

    public function test_a_unique_block_cannot_appear_twice(): void
    {
        $errors = $this->errors([['type' => 'custom_order'], ['type' => 'custom_order']]);

        $this->assertArrayHasKey('blocks.1.children.0.type', $errors);
    }

    public function test_images_must_exist(): void
    {
        $media = Media::factory()->create();

        [$block] = $this->elements([['type' => 'text_image', 'settings' => ['image' => (string) $media->id]]]);
        $this->assertSame($media->id, $block['settings']['image']);

        $this->assertArrayHasKey('blocks.0.children.0.settings.image', $this->errors([['type' => 'text_image', 'settings' => ['image' => 999]]]));
    }

    public function test_repeaters_are_cleaned_item_by_item(): void
    {
        $errors = $this->errors([['type' => 'faq', 'content' => ['pt' => ['items' => [
            ['question' => 'Uma?', 'answer' => '<p>Sim</p>'],
            ['question' => '', 'answer' => '<p>Falta a pergunta</p>'],
        ]]]]]);

        $this->assertArrayHasKey('blocks.0.children.0.content.pt.items.1.question', $errors);

        $tooMany = array_fill(0, 7, ['title' => 'Passo']);
        $this->assertArrayHasKey('blocks.0.children.0.content.pt.steps', $this->errors([['type' => 'steps', 'content' => ['pt' => ['steps' => $tooMany]]]]));
    }

    public function test_numbers_selects_and_icons_fall_back_or_refuse(): void
    {
        $this->assertArrayHasKey('blocks.0.children.0.settings.limit', $this->errors([['type' => 'product_grid', 'settings' => ['limit' => 500]]]));

        [$block] = $this->elements([['type' => 'product_grid', 'settings' => ['source' => 'tudo', 'columns' => '7']]]);
        $this->assertSame('featured', $block['settings']['source']);
        $this->assertSame('4', $block['settings']['columns']);

        [$block] = $this->elements([['type' => 'trust', 'content' => ['pt' => ['items' => [['icon' => 'skull', 'title' => 'x']]]]]]);
        $this->assertSame('sparkles', $block['content']['pt']['items'][0]['icon']);
    }

    public function test_ids_survive_and_duplicates_are_renamed(): void
    {
        $blocks = $this->elements([
            $this->text('<p>a</p>', ['id' => 'bloco-1']),
            $this->text('<p>b</p>', ['id' => 'bloco-1']),
        ]);

        $this->assertSame('bloco-1', $blocks[0]['id']);
        $this->assertNotSame('bloco-1', $blocks[1]['id']);
    }

    public function test_nested_tree_is_kept_and_cleaned(): void
    {
        [$root] = $this->clean([$this->container([
            $this->container([$this->text('<p>Esquerda</p>', ['id' => 't1', 'label' => '  <b>Intro</b> '])], [
                'id' => 'col-1',
                'style' => ['width' => ['desktop' => 66, 'mobile' => 100]],
            ]),
            $this->container([['id' => 's1', 'type' => 'spacer', 'shell' => false, 'settings' => ['size' => 'lg']]], [
                'id' => 'col-2',
                'style' => ['width' => ['desktop' => 34]],
            ]),
        ], [
            'id' => 'sec',
            'label' => 'Secção',
            'style' => [
                'direction' => ['desktop' => 'row', 'mobile' => 'column', 'phone' => 'row'],
                'gap' => ['desktop' => '24'],
                'bg' => ['desktop' => 'muted'],
                'nada' => ['desktop' => 'x'],
            ],
            'advanced' => ['margin_top' => ['tablet' => 40], 'hide' => ['mobile' => true]],
            'settings' => ['lixo' => 1],
            'content' => ['pt' => ['lixo' => 1]],
        ])]);

        $this->assertSame([
            'id' => 'sec',
            'type' => 'container',
            'v' => 2,
            'visible' => true,
            'label' => 'Secção',
            'settings' => [],
            'content' => [],
            'style' => [
                'direction' => ['desktop' => 'row', 'mobile' => 'column'],
                'gap' => ['desktop' => 24],
                'bg' => ['desktop' => 'muted'],
            ],
            'advanced' => ['margin_top' => ['tablet' => 40], 'hide' => ['mobile' => true]],
        ], array_diff_key($root, ['children' => true]));

        [$left, $right] = $root['children'];
        $this->assertSame('col-1', $left['id']);
        $this->assertSame(['width' => ['desktop' => 66, 'mobile' => 100]], $left['style']);
        $this->assertSame('col-2', $right['id']);

        $text = $left['children'][0];
        $this->assertSame('t1', $text['id']);
        $this->assertSame('Intro', $text['label']);
        $this->assertTrue($text['shell']);
        $this->assertSame('<p>Esquerda</p>', $text['content']['pt']['body']);

        $spacer = $right['children'][0];
        $this->assertSame('s1', $spacer['id']);
        $this->assertFalse($spacer['shell']);
        $this->assertSame('lg', $spacer['settings']['size']);
    }

    public function test_style_values_out_of_range_are_refused_at_their_path(): void
    {
        $errors = $this->errors([$this->container([$this->text('<p>x</p>', [
            'advanced' => ['padding_y' => ['desktop' => 999]],
        ])], [
            'style' => ['bg' => ['desktop' => '#ff0000'], 'bg_image' => ['desktop' => 999]],
        ])]);

        $this->assertArrayHasKey('blocks.0.style.bg.desktop', $errors);
        $this->assertArrayHasKey('blocks.0.style.bg_image.desktop', $errors);
        $this->assertArrayHasKey('blocks.0.children.0.advanced.padding_y.desktop', $errors);

        $media = Media::factory()->create();
        [$root] = $this->clean([$this->container([$this->text('<p>x</p>')], [
            'style' => ['bg_image' => ['desktop' => (string) $media->id]],
        ])]);
        $this->assertSame(['bg_image' => ['desktop' => $media->id]], $root['style']);
    }

    public function test_labels_are_short_plain_text(): void
    {
        [$root] = $this->clean([$this->container([$this->text('<p>x</p>', ['label' => '   '])], ['label' => 42])]);
        $this->assertNull($root['label']);
        $this->assertNull($root['children'][0]['label']);

        // Um nome comprido corta-se sem recusar a gravacao.
        $long = $this->container([$this->text('<p>x</p>')], ['label' => str_repeat('a', 61)]);
        $this->assertSame(str_repeat('a', 60), $this->clean([$long])[0]['label']);
    }

    public function test_node_and_depth_limits(): void
    {
        $five = $this->container([$this->container([$this->container([$this->container([
            $this->container([$this->text('<p>fundo</p>')]),
        ])])])]);

        $errors = $this->errors([$five]);
        $this->assertSame(
            'Não se pode meter mais de 4 contentores uns dentro dos outros.',
            $errors['blocks.0.children.0.children.0.children.0.children.0'],
        );

        // Quatro chegam.
        $four = $this->container([$this->container([$this->container([$this->container([$this->text('<p>x</p>')])])])]);
        $this->assertCount(1, $this->clean([$four]));

        // 1 contentor + 400 elementos = 401 nos.
        $tooMany = $this->container(array_fill(0, Container::MAX_NODES, $this->text('<p>x</p>')));
        $this->assertArrayHasKey('blocks', $this->errors([$tooMany]));

        $atLimit = $this->container(array_fill(0, Container::MAX_NODES - 1, $this->text('<p>x</p>')));
        $this->assertSame(Container::MAX_NODES, PageDocument::count($this->clean([$atLimit])));
    }

    public function test_row_container_with_seven_children_is_refused(): void
    {
        $row = fn (int $n): array => $this->container(
            array_fill(0, $n, $this->container([])),
            ['style' => ['direction' => ['desktop' => 'row']]],
        );

        $this->assertArrayHasKey('blocks.0', $this->errors([$row(7)]));
        $this->assertCount(6, $this->clean([$row(6)])[0]['children']);

        // Em coluna nao ha esse limite.
        $column = $this->container(array_fill(0, 7, $this->container([])));
        $this->assertCount(7, $this->clean([$column])[0]['children']);
    }

    public function test_row_on_any_device_counts_for_the_children_limit(): void
    {
        $with = fn (array $direction): array => $this->container(
            array_fill(0, 7, $this->container([])),
            ['style' => ['direction' => $direction]],
        );

        // Coluna no computador mas linha no tablet (herdada pelo telemovel).
        $this->assertArrayHasKey('blocks.0', $this->errors([$with(['desktop' => 'column', 'tablet' => 'row'])]));
        // Linha no computador e coluna no telemovel continua a contar.
        $this->assertArrayHasKey('blocks.0', $this->errors([$with(['desktop' => 'row', 'mobile' => 'column'])]));
        // So o telemovel em linha tambem.
        $this->assertArrayHasKey('blocks.0', $this->errors([$with(['desktop' => 'column', 'mobile' => 'row'])]));
        // Sempre em coluna passa.
        $this->assertCount(7, $this->clean([$with(['desktop' => 'column', 'tablet' => 'column'])])[0]['children']);
    }

    public function test_root_only_block_inside_a_column_is_refused(): void
    {
        $main = ['id' => 'pm', 'type' => 'product_main'];

        $errors = $this->errors([$this->container([$this->container([$main])])], 'product');
        $this->assertSame(
            'O bloco "Produto: galeria e escolha" só pode ficar sozinho numa secção da página.',
            $errors['blocks.0.children.0.children.0'],
        );

        // Ao lado de outro elemento num contentor de raiz tambem nao.
        $this->assertArrayHasKey('blocks.0.children.1', $this->errors([$this->container([$this->text('<p>x</p>'), $main])], 'product'));

        // Sozinho num contentor de raiz, sim (e o que o upgrade faz).
        $this->assertSame('pm', $this->clean([$main], 'product')[0]['children'][0]['id']);
    }

    public function test_unique_counts_across_the_tree(): void
    {
        $errors = $this->errors([
            ['type' => 'custom_order'],
            $this->container([$this->container([['type' => 'custom_order']])]),
        ]);

        $this->assertArrayHasKey('blocks.1.children.0.children.0.type', $errors);

        // Os obrigatorios contam-se onde quer que estejam.
        $this->assertArrayHasKey('blocks', $this->errors([$this->container([$this->text('<p>x</p>')])], 'product'));
        $this->assertCount(1, $this->clean([['type' => 'product_main']], 'product'));
    }

    public function test_duplicate_ids_across_the_tree_are_renumbered(): void
    {
        [$root] = $this->clean([$this->container([
            $this->container([$this->text('<p>a</p>', ['id' => 'x'])], ['id' => 'x']),
            $this->text('<p>b</p>', ['id' => 'x']),
            $this->text('<p>c</p>', ['id' => 'sec']),
        ], ['id' => 'sec'])]);

        $ids = [];
        PageDocument::walk([$root], function (array $node) use (&$ids): void {
            $ids[] = $node['id'];
        });

        $this->assertCount(5, $ids);
        $this->assertSame($ids, array_unique($ids));
        $this->assertSame('sec', $ids[0]);
        $this->assertSame('x', $ids[1]);

        // Dois blocos v1 sem id davam dois contentores "c-".
        $roots = $this->clean([$this->text('<p>a</p>'), $this->text('<p>b</p>')]);
        $this->assertNotSame($roots[0]['id'], $roots[1]['id']);
    }

    public function test_preview_never_throws_and_drops_unknown_nodes(): void
    {
        $sanitizer = app(BlockSanitizer::class);

        $deep = $this->container([$this->container([$this->container([$this->container([
            $this->container([$this->text('<p>fundo</p>', ['id' => 'fundo'])]),
        ])])])]);

        $tree = $sanitizer->preview([
            $this->container([
                ['id' => 'a', 'type' => 'nao-existe'],
                'lixo',
                $this->text('<p>ok</p>', ['id' => 'ok']),
                ['id' => 'pm', 'type' => 'product_main'],
                $this->container([], ['children' => 'lixo']),
                ['type' => 'text', 'id' => ['x']],
                ['type' => ['x']],
            ]),
            ['type' => 'text', 'id' => ['x']],
            ['type' => ['x']],
            $deep,
            $this->container(array_fill(0, Container::MAX_NODES + 50, $this->text('<p>x</p>'))),
        ], 'page');

        $this->assertCount(2, $tree[0]['children']);
        $this->assertSame('ok', $tree[0]['children'][0]['id']);
        $this->assertSame([], $tree[0]['children'][1]['children']);
        $this->assertNull(PageDocument::find($tree, 'a'));
        $this->assertNull(PageDocument::find($tree, 'pm'));
        $this->assertNull(PageDocument::find($tree, 'fundo'));
        $this->assertLessThanOrEqual(Container::MAX_NODES, PageDocument::count($tree));

        $this->assertSame([], $sanitizer->preview(['a' => 1], 'page'));
    }

    public function test_clean_refuses_non_scalar_ids_and_types_with_a_validation_error(): void
    {
        foreach ([
            [['type' => 'text', 'id' => ['x']]],
            [['type' => ['x']]],
            [$this->container([['type' => 'text', 'id' => ['x']]])],
            [$this->container([['type' => ['x']]])],
        ] as $nodes) {
            $this->assertNotEmpty($this->errors($nodes));
        }
    }
}
