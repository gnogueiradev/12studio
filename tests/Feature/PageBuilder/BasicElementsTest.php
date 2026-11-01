<?php

namespace Tests\Feature\PageBuilder;

use App\PageBuilder\BlockRegistry;
use App\PageBuilder\BlockSanitizer;
use App\PageBuilder\VideoEmbed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Os nove elementos basicos (titulo, texto, imagem, botao, icone, divisor,
 * video, lista com icones, citacao): sem casca de seccao, com estilo proprio.
 */
class BasicElementsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O elemento limpo (os v1 entram embrulhados num contentor de raiz).
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function element(array $block): array
    {
        return app(BlockSanitizer::class)->clean([$block], 'page')[0]['children'][0];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, string>
     */
    private function errors(array $block): array
    {
        try {
            app(BlockSanitizer::class)->clean([$block], 'page');
        } catch (ValidationException $exception) {
            return array_map(fn (array $messages): string => $messages[0], $exception->errors());
        }

        return [];
    }

    public function test_the_nine_elements_are_registered_without_a_shell(): void
    {
        foreach (['heading', 'paragraph', 'image', 'button', 'icon', 'divider', 'video', 'icon_list', 'quote'] as $type) {
            $block = app(BlockRegistry::class)->get($type);

            $this->assertNotNull($block, $type);
            $this->assertFalse($block->hasShell(), $type);
            $this->assertSame(['page', 'catalog', 'product'], $block->templates(), $type);
            $this->assertNotEmpty($block->styleFields(), $type);
        }
    }

    public function test_heading_requires_text_and_cleans_level(): void
    {
        $errors = $this->errors(['type' => 'heading', 'content' => ['pt' => ['text' => '']]]);
        $this->assertArrayHasKey('blocks.0.children.0.content.pt.text', $errors);

        $element = $this->element([
            'type' => 'heading',
            'content' => ['pt' => ['text' => 'Olá']],
            'styleProps' => ['level' => ['desktop' => 'h2'], 'size' => ['desktop' => 'xl']],
        ]);

        $this->assertSame('Olá', $element['content']['pt']['text']);
        $this->assertSame(['desktop' => 'h2'], $element['styleProps']['level']);
        $this->assertSame(['desktop' => 'xl'], $element['styleProps']['size']);
        $this->assertFalse($element['shell']);

        $errors = $this->errors(['type' => 'heading', 'content' => ['pt' => ['text' => 'Olá']], 'styleProps' => ['level' => ['tablet' => 'h9']]]);
        $this->assertArrayHasKey('blocks.0.children.0.styleProps.level.tablet', $errors);
    }

    public function test_paragraph_is_purified(): void
    {
        $element = $this->element([
            'type' => 'paragraph',
            'content' => ['pt' => ['body' => '<p>Olá</p><script>alert(1)</script>']],
        ]);

        $this->assertStringContainsString('Olá', $element['content']['pt']['body']);
        $this->assertStringNotContainsString('<script', $element['content']['pt']['body']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function videoUrls(): array
    {
        return [
            'youtube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'youtu.be' => ['https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'youtube shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'vimeo' => ['https://vimeo.com/76979871', 'https://player.vimeo.com/video/76979871'],
            'ja em embed (regravar)' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'youtube embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'youtube live' => ['youtube.com/live/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'http' => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'sem esquema' => ['youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'sem esquema com www' => ['www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'youtu.be sem esquema' => ['youtu.be/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'youtu.be com si' => ['https://youtu.be/dQw4w9WgXcQ?si=abcDEF123', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'parametros extra antes e depois' => ['https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=30s', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'm.youtube' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'vimeo http' => ['http://vimeo.com/123', 'https://player.vimeo.com/video/123'],
            'vimeo privado' => ['https://vimeo.com/76979871/a1B2c3D4e5', 'https://player.vimeo.com/video/76979871?h=a1B2c3D4e5'],
            'vimeo privado ja em embed (regravar)' => ['https://player.vimeo.com/video/76979871?h=a1B2c3D4e5', 'https://player.vimeo.com/video/76979871?h=a1B2c3D4e5'],
            'vimeo ja em embed (regravar)' => ['https://player.vimeo.com/video/76979871', 'https://player.vimeo.com/video/76979871'],
        ];
    }

    #[DataProvider('videoUrls')]
    public function test_video_accepts_youtube_and_vimeo_urls(string $url, string $embed): void
    {
        $this->assertSame($embed, VideoEmbed::fromUrl($url));

        $element = $this->element(['type' => 'video', 'settings' => ['url' => $url]]);

        $this->assertSame($embed, $element['settings']['url']);

        // Gravar outra vez o que ja foi gravado nao muda nada.
        $this->assertSame($embed, $this->element(['type' => 'video', 'settings' => ['url' => $element['settings']['url']]])['settings']['url']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badVideoUrls(): array
    {
        return [
            'outro dominio' => ['https://evil.example/x'],
            'javascript' => ['javascript:alert(1)'],
            'youtube falso' => ['https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'],
            'id curto' => ['https://youtu.be/abc'],
            'dominio parecido sem esquema' => ['youtube.com.evil.example/watch?v=dQw4w9WgXcQ'],
            'userinfo' => ['https://youtube.com@evil.example/watch?v=dQw4w9WgXcQ'],
            'userinfo sem esquema' => ['youtu.be@evil.example/dQw4w9WgXcQ'],
            'youtube no caminho de outro host' => ['https://evil.example/youtube.com/watch?v=dQw4w9WgXcQ'],
            'vimeo falso' => ['https://vimeo.com.evil.example/123/abc'],        ];
    }

    #[DataProvider('badVideoUrls')]
    public function test_video_rejects_other_hosts(string $url): void
    {
        $this->assertNull(VideoEmbed::fromUrl($url));

        $errors = $this->errors(['type' => 'video', 'settings' => ['url' => $url]]);

        $this->assertSame('Só vídeos do YouTube ou do Vimeo.', $errors['blocks.0.children.0.settings.url'] ?? null);
    }

    public function test_video_keeps_the_earlier_error_at_the_url_path(): void
    {
        $errors = $this->errors(['type' => 'video', 'settings' => ['url' => 'https://evil.example/'.str_repeat('a', 300)]]);

        $this->assertStringContainsString('300', $errors['blocks.0.children.0.settings.url']);
    }

    public function test_video_requires_a_url(): void
    {
        $this->assertArrayHasKey('blocks.0.children.0.settings.url', $this->errors(['type' => 'video', 'settings' => ['url' => '']]));
    }

    public function test_the_other_elements_clean_their_fields_and_styles(): void
    {
        $list = $this->element([
            'type' => 'icon_list',
            'content' => ['pt' => ['items' => [['icon' => 'star', 'text' => 'Feito à mão', 'link' => null]]]],
            'styleProps' => ['gap' => ['desktop' => 12], 'color' => ['desktop' => 'gold']],
        ]);
        $this->assertSame('Feito à mão', $list['content']['pt']['items'][0]['text']);
        $this->assertSame(['desktop' => 12], $list['styleProps']['gap']);

        $divider = $this->element(['type' => 'divider', 'styleProps' => ['width' => ['tablet' => 50]]]);
        $this->assertSame(['tablet' => 50], $divider['styleProps']['width']);
        $this->assertArrayHasKey('blocks.0.children.0.styleProps.width.desktop', $this->errors(['type' => 'divider', 'styleProps' => ['width' => ['desktop' => 500]]]));

        $quote = $this->element(['type' => 'quote', 'content' => ['pt' => ['text' => 'Belo', 'author' => 'Ana']]]);
        $this->assertSame('Ana', $quote['content']['pt']['author']);

        $button = $this->element(['type' => 'button', 'content' => ['pt' => ['link' => ['label' => 'Ver', 'href' => '/produtos']]]]);
        $this->assertSame('/produtos', $button['content']['pt']['link']['href']);

        $this->assertArrayHasKey('blocks.0.children.0.content.pt.link', $this->errors(['type' => 'button']));
        $this->assertArrayHasKey('blocks.0.children.0.settings.image', $this->errors(['type' => 'image']));

        $icon = $this->element(['type' => 'icon', 'settings' => ['icon' => 'heart'], 'styleProps' => ['size' => ['desktop' => 64]]]);
        $this->assertSame('heart', $icon['settings']['icon']);
    }
}
