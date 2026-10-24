<?php

namespace Tests\Feature\PageBuilder;

use App\Models\Media;
use App\PageBuilder\BlockSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tudo o que um bloco grava passa por aqui. O que sai e o que a montra
 * renderiza — por isso nada desconhecido, nada de HTML perigoso, nenhum link
 * para fora do que se espera.
 */
class BlockSanitizerTest extends TestCase
{
    use RefreshDatabase;

    private function clean(array $blocks, string $template = 'page'): array
    {
        return app(BlockSanitizer::class)->clean($blocks, $template);
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

    public function test_a_valid_block_comes_out_normalized(): void
    {
        [$block] = $this->clean([$this->text('<p>Olá</p>')]);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $block['id']);
        $this->assertSame('text', $block['type']);
        $this->assertSame('narrow', $block['variant']);
        $this->assertTrue($block['visible']);
        $this->assertSame(['bg' => 'default', 'spacing' => 'md', 'width' => 'contained', 'align' => 'start'], $block['style']);
        $this->assertSame(['title' => '', 'body' => '<p>Olá</p>'], $block['content']['pt']);
        $this->assertSame([], $block['content']['en']);
    }

    public function test_unknown_types_variants_and_styles(): void
    {
        $this->assertArrayHasKey('blocks.0.type', $this->errors([['type' => 'iframe']]));

        [$block] = $this->clean([$this->text('<p>x</p>', [
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
        [$block] = $this->clean([$this->text('<p onclick="x()">Olá<script>alert(1)</script><img src=x onerror=y> <a href="javascript:alert(1)">link</a></p>')]);

        $html = $block['content']['pt']['body'];
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('Olá', $html);
    }

    public function test_an_empty_editor_counts_as_empty(): void
    {
        $this->assertArrayHasKey('blocks.0.content.pt.body', $this->errors([$this->text('<p><br></p>')]));
    }

    public function test_required_fields_are_only_required_in_the_default_language(): void
    {
        $errors = $this->errors([['type' => 'banner', 'content' => ['pt' => ['title' => '']]]]);
        $this->assertArrayHasKey('blocks.0.content.pt.title', $errors);

        [$block] = $this->clean([['type' => 'banner', 'content' => ['pt' => ['title' => 'Olá'], 'en' => ['title' => '']]]]);
        $this->assertSame([], $block['content']['en']);
    }

    public function test_links_only_go_where_they_should(): void
    {
        foreach (['javascript:alert(1)', '//evil.example', '/\\evil.example', 'http://inseguro.pt', 'data:text/html,x', 'ftp://x'] as $href) {
            $errors = $this->errors([['type' => 'banner', 'content' => ['pt' => [
                'title' => 'Olá', 'link' => ['label' => 'Ir', 'href' => $href],
            ]]]]);

            $this->assertArrayHasKey('blocks.0.content.pt.link.href', $errors, $href);
        }

        foreach (['/produtos', '/produtos?cor=azul', 'https://www.etsy.com/shop/x', 'mailto:ola@loja.pt', 'tel:+351912345678', '#pedido'] as $href) {
            [$block] = $this->clean([['type' => 'banner', 'content' => ['pt' => [
                'title' => 'Olá', 'link' => ['label' => 'Ir', 'href' => $href],
            ]]]]);

            $this->assertSame($href, $block['content']['pt']['link']['href']);
        }
    }

    public function test_a_unique_block_cannot_appear_twice(): void
    {
        $errors = $this->errors([['type' => 'custom_order'], ['type' => 'custom_order']]);

        $this->assertArrayHasKey('blocks.1.type', $errors);
    }

    public function test_images_must_exist(): void
    {
        $media = Media::factory()->create();

        [$block] = $this->clean([['type' => 'text_image', 'settings' => ['image' => (string) $media->id]]]);
        $this->assertSame($media->id, $block['settings']['image']);

        $this->assertArrayHasKey('blocks.0.settings.image', $this->errors([['type' => 'text_image', 'settings' => ['image' => 999]]]));
    }

    public function test_repeaters_are_cleaned_item_by_item(): void
    {
        $errors = $this->errors([['type' => 'faq', 'content' => ['pt' => ['items' => [
            ['question' => 'Uma?', 'answer' => '<p>Sim</p>'],
            ['question' => '', 'answer' => '<p>Falta a pergunta</p>'],
        ]]]]]);

        $this->assertArrayHasKey('blocks.0.content.pt.items.1.question', $errors);

        $tooMany = array_fill(0, 7, ['title' => 'Passo']);
        $this->assertArrayHasKey('blocks.0.content.pt.steps', $this->errors([['type' => 'steps', 'content' => ['pt' => ['steps' => $tooMany]]]]));
    }

    public function test_numbers_selects_and_icons_fall_back_or_refuse(): void
    {
        $this->assertArrayHasKey('blocks.0.settings.limit', $this->errors([['type' => 'product_grid', 'settings' => ['limit' => 500]]]));

        [$block] = $this->clean([['type' => 'product_grid', 'settings' => ['source' => 'tudo', 'columns' => '7']]]);
        $this->assertSame('featured', $block['settings']['source']);
        $this->assertSame('4', $block['settings']['columns']);

        [$block] = $this->clean([['type' => 'trust', 'content' => ['pt' => ['items' => [['icon' => 'skull', 'title' => 'x']]]]]]);
        $this->assertSame('sparkles', $block['content']['pt']['items'][0]['icon']);
    }

    public function test_ids_survive_and_duplicates_are_renamed(): void
    {
        $blocks = $this->clean([
            $this->text('<p>a</p>', ['id' => 'bloco-1']),
            $this->text('<p>b</p>', ['id' => 'bloco-1']),
        ]);

        $this->assertSame('bloco-1', $blocks[0]['id']);
        $this->assertNotSame('bloco-1', $blocks[1]['id']);
    }

    public function test_a_page_has_a_ceiling(): void
    {
        $this->assertArrayHasKey('blocks', $this->errors(array_fill(0, BlockSanitizer::MAX_BLOCKS + 1, $this->text('<p>x</p>'))));
    }
}
