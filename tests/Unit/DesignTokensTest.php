<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Teste-guarda de design (padrao qrcode, irmao do ConfigCacheSafetyTest).
 *
 * A paleta da marca vive em variaveis CSS, longe de qualquer codigo PHP, e
 * por isso nada a protege: um `bg-neutral-200` copiado de um exemplo do
 * shadcn passa em code review sem ninguem reparar, e so se ve meses depois
 * quando alguem nota que metade do backoffice esta cinzento-azulado. Pior
 * ainda com contraste: aclarar um tom "so um bocadinho" pode empurrar texto
 * abaixo do minimo WCAG sem sinal nenhum. Este teste le o CSS, resolve os
 * tokens e faz as contas.
 */
class DesignTokensTest extends TestCase
{
    public function test_light_theme_uses_the_brand_palette(): void
    {
        $expected = [
            'background' => '#FAF8F5',
            'foreground' => '#332F2B',
            'card' => '#FFFFFF',
            'card-foreground' => '#332F2B',
            'popover' => '#FFFFFF',
            'popover-foreground' => '#332F2B',
            'primary' => '#332F2B',
            'primary-foreground' => '#FAF8F5',
            'primary-hover' => '#7F7061',
            'secondary' => '#F1ECE5',
            'secondary-foreground' => '#332F2B',
            'secondary-hover' => '#DDD6CD',
            'muted' => '#F1ECE5',
            'muted-foreground' => '#756D65',
            'accent' => '#F1ECE5',
            'accent-foreground' => '#332F2B',
            'border' => '#DDD6CD',
            'input' => '#DDD6CD',
            'ring' => '#A68C67',
            'gold' => '#C6A77B',
            'brand-taupe' => '#A99582',
            'destructive' => '#A63D2E',
            'destructive-foreground' => '#FAF8F5',
            'destructive-soft' => '#F7E7E2',
            'destructive-soft-foreground' => '#7A2C21',
            'success' => '#4F6B45',
            'success-foreground' => '#FAF8F5',
            'success-soft' => '#E8EEE3',
            'success-soft-foreground' => '#3B5133',
            'warning' => '#97691E',
            'warning-foreground' => '#FAF8F5',
            'warning-soft' => '#F6EBD6',
            'warning-soft-foreground' => '#6E4C15',
            'info' => '#3B5560',
            'info-foreground' => '#FAF8F5',
            'info-soft' => '#E3EBED',
            'info-soft-foreground' => '#2F454E',
            'sidebar' => '#F1ECE5',
            'sidebar-foreground' => '#332F2B',
            'sidebar-primary' => '#332F2B',
            'sidebar-primary-foreground' => '#FAF8F5',
            'sidebar-accent' => '#DDD6CD',
            'sidebar-accent-foreground' => '#332F2B',
            'sidebar-border' => '#DDD6CD',
            'sidebar-ring' => '#A68C67',
        ];

        $tokens = $this->tokensIn(':root');

        foreach ($expected as $name => $value) {
            $this->assertArrayHasKey($name, $tokens, "Falta o token --{$name} no bloco :root de app.css.");
            $this->assertSame($value, $tokens[$name], "O token --{$name} devia ser {$value}.");
        }
    }

    /**
     * Pares (token do texto, token do fundo, racio minimo).
     *
     * Referem-se a tokens e nao a hexes: assim o teste le os valores reais do
     * CSS e falha se alguem mexer numa cor sem refazer as contas. Os dois
     * temas partilham a lista de proposito — o modo escuro e sujeito a
     * exatamente a mesma fasquia que o claro.
     *
     * @return array<string, array{string, string, float}>
     */
    public static function contrastPairs(): array
    {
        return [
            'texto sobre o fundo' => ['foreground', 'background', 4.5],
            'texto de apoio sobre o fundo' => ['muted-foreground', 'background', 4.5],
            'texto sobre o cartao' => ['card-foreground', 'card', 4.5],
            'botao primario' => ['primary-foreground', 'primary', 4.5],
            'botao primario em hover' => ['primary-foreground', 'primary-hover', 4.5],
            'botao secundario' => ['secondary-foreground', 'secondary', 4.5],
            'botao secundario em hover' => ['secondary-foreground', 'secondary-hover', 4.5],
            'anel de foco sobre o fundo' => ['ring', 'background', 3.0],
            'erro sobre o fundo' => ['destructive', 'background', 4.5],
            'texto sobre o erro solido' => ['destructive-foreground', 'destructive', 4.5],
            'sucesso sobre o fundo' => ['success', 'background', 4.5],
            'aviso sobre o fundo' => ['warning', 'background', 4.5],
            'info sobre o fundo' => ['info', 'background', 4.5],
            'badge de erro' => ['destructive-soft-foreground', 'destructive-soft', 4.5],
            'badge de sucesso' => ['success-soft-foreground', 'success-soft', 4.5],
            'badge de aviso' => ['warning-soft-foreground', 'warning-soft', 4.5],
            'badge de info' => ['info-soft-foreground', 'info-soft', 4.5],
        ];
    }

    #[DataProvider('contrastPairs')]
    public function test_light_theme_meets_wcag(string $foreground, string $background, float $minimum): void
    {
        $this->assertContrast(':root', $foreground, $background, $minimum);
    }

    public function test_dark_theme_uses_the_derived_warm_palette(): void
    {
        $expected = [
            'background' => '#211E1C',
            'foreground' => '#FAF8F5',
            'card' => '#2A2624',
            'card-foreground' => '#FAF8F5',
            'popover' => '#2A2624',
            'popover-foreground' => '#FAF8F5',
            'primary' => '#FAF8F5',
            'primary-foreground' => '#332F2B',
            'primary-hover' => '#DDD6CD',
            'secondary' => '#332F2B',
            'secondary-foreground' => '#FAF8F5',
            'secondary-hover' => '#413B36',
            'muted' => '#332F2B',
            'muted-foreground' => '#A99582',
            'accent' => '#332F2B',
            'accent-foreground' => '#FAF8F5',
            'border' => '#413B36',
            'input' => '#413B36',
            'ring' => '#C6A77B',
            'gold' => '#C6A77B',
            'brand-taupe' => '#A99582',
            'destructive' => '#D4705E',
            'destructive-foreground' => '#211E1C',
            'destructive-soft' => '#3A2320',
            'destructive-soft-foreground' => '#E6A99C',
            'success' => '#8FAE7F',
            'success-foreground' => '#211E1C',
            'success-soft' => '#26301F',
            'success-soft-foreground' => '#A9C599',
            'warning' => '#D9A84E',
            'warning-foreground' => '#211E1C',
            'warning-soft' => '#38290F',
            'warning-soft-foreground' => '#E3BE73',
            'info' => '#9FC0C9',
            'info-foreground' => '#211E1C',
            'info-soft' => '#1F2E33',
            'info-soft-foreground' => '#A9C9D2',
            'sidebar' => '#2A2624',
            'sidebar-foreground' => '#FAF8F5',
            'sidebar-primary' => '#FAF8F5',
            'sidebar-primary-foreground' => '#332F2B',
            'sidebar-accent' => '#413B36',
            'sidebar-accent-foreground' => '#FAF8F5',
            'sidebar-border' => '#413B36',
            'sidebar-ring' => '#C6A77B',
        ];

        $tokens = $this->tokensIn('.dark');

        foreach ($expected as $name => $value) {
            $this->assertArrayHasKey($name, $tokens, "Falta o token --{$name} no bloco .dark de app.css.");
            $this->assertSame($value, $tokens[$name], "O token --{$name} do modo escuro devia ser {$value}.");
        }
    }

    /**
     * Reutiliza o provider da Tarefa 1. Um tema escuro nao e o claro
     * invertido — a percecao de luminosidade nao e simetrica, e ha tokens que
     * trocam de papel (o --muted-foreground e taupe no escuro, porque o
     * cinzento-quente da paleta afundava-se a 2.0:1 contra o fundo) — mas a
     * fasquia de contraste e a mesma, e por isso a lista de pares tambem.
     */
    #[DataProvider('contrastPairs')]
    public function test_dark_theme_meets_wcag(string $foreground, string $background, float $minimum): void
    {
        $this->assertContrast('.dark', $foreground, $background, $minimum);
    }

    public function test_no_oklch_survives_in_the_stylesheet(): void
    {
        $this->assertStringNotContainsString(
            'oklch(',
            $this->css(),
            'app.css ainda tem cores em oklch: a paleta da marca e definida em hex.'
        );
    }

    public function test_theme_block_exposes_the_new_tokens(): void
    {
        $utilities = [
            'primary-hover',
            'secondary-hover',
            'gold',
            'brand-taupe',
            'destructive-soft',
            'destructive-soft-foreground',
            'success',
            'success-foreground',
            'success-soft',
            'success-soft-foreground',
            'warning',
            'warning-foreground',
            'warning-soft',
            'warning-soft-foreground',
            'info',
            'info-foreground',
            'info-soft',
            'info-soft-foreground',
        ];

        $pattern = '/^@theme\s*\{(.*?)^\}/ms';
        preg_match($pattern, $this->css(), $block);

        foreach ($utilities as $utility) {
            $this->assertStringContainsString(
                "--color-{$utility}: var(--{$utility});",
                $block[1] ?? '',
                "O bloco @theme nao expoe --color-{$utility}: sem isto o Tailwind ignora a classe em silencio."
            );
        }
    }

    public function test_buttons_use_the_palette_hover_colours(): void
    {
        $button = file_get_contents($this->projectPath('resources/js/components/ui/button.tsx'));

        $this->assertStringContainsString('hover:bg-primary-hover', $button);
        $this->assertStringContainsString('hover:bg-secondary-hover', $button);
        $this->assertStringNotContainsString('hover:bg-primary/90', $button);
        $this->assertStringNotContainsString('hover:bg-secondary/80', $button);
    }

    /**
     * O <style> inline do Blade pinta o fundo antes de o CSS do Vite carregar.
     * Se continuar em oklch(1 0 0)/oklch(0.145 0 0) do starter kit, uma
     * cache fria mostra um flash branco antes de saltar para o bege da marca.
     */
    public function test_the_anti_flash_style_matches_the_palette(): void
    {
        $blade = file_get_contents($this->projectPath('resources/views/app.blade.php'));

        $this->assertStringNotContainsString(
            'oklch(',
            $blade,
            'O <style> inline do Blade ainda pinta o fundo em oklch: com cache fria a pagina abre com um flash branco antes de saltar para o bege.'
        );
        $this->assertStringContainsString('background-color: #FAF8F5;', $blade);
        $this->assertStringContainsString('background-color: #211E1C;', $blade);
    }

    /**
     * A barra de progresso do Inertia ainda vinha no cinzento-azulado do
     * starter kit (#4B5563), que nao pertence a paleta da marca.
     */
    public function test_the_progress_bar_uses_the_brand_colour(): void
    {
        $app = file_get_contents($this->projectPath('resources/js/app.tsx'));

        $this->assertStringNotContainsString('#4B5563', $app, 'A barra de progresso do Inertia ainda esta no cinzento-azulado do starter kit.');
        $this->assertStringContainsString("color: '#332F2B'", $app);
    }

    /**
     * O QR code do 2FA precisa de branco puro: os leitores dependem do
     * contraste maximo e um fundo bege reduz a taxa de leitura. E a unica
     * excecao permitida, e por isso vive aqui e nao num comentario perdido.
     */
    private const COLOUR_EXCEPTIONS = [
        'resources/js/components/two-factor-setup-modal.tsx' => ['bg-white'],
    ];

    /**
     * As familias sao duas listas porque a limpeza foi feita em duas fases: os
     * cinzentos de marca primeiro, as cores de estado depois. Ficam separadas
     * para o proximo que quiser perceber porque e que um `bg-emerald-100` e
     * tratado de forma diferente de um `bg-neutral-100`.
     */
    private const BRAND_FAMILIES = 'neutral|zinc|gray|slate|stone|white|black';

    private const STATE_FAMILIES = 'red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';

    private const COLOUR_PREFIXES = 'bg|text|border|from|to|via|ring|fill|stroke|divide|outline|shadow|decoration|accent|caret|placeholder';

    public function test_no_neutral_brand_colours_remain_in_the_frontend(): void
    {
        $this->assertNoColourClasses(self::BRAND_FAMILIES);
    }

    protected function assertNoColourClasses(string $families): void
    {
        $pattern = '/\b(?:'.self::COLOUR_PREFIXES.')-(?:'.$families.')(?:-\d{2,3})?\b/';

        $offenders = [];

        foreach ($this->tsxFiles() as $relative => $absolute) {
            preg_match_all($pattern, file_get_contents($absolute), $matches);

            $allowed = self::COLOUR_EXCEPTIONS[$relative] ?? [];
            $found = array_diff(array_unique($matches[0]), $allowed);

            if ($found !== []) {
                $offenders[] = $relative.': '.implode(', ', $found);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Cores fixas da paleta do Tailwind em resources/js — use os tokens da marca:\n".implode("\n", $offenders)
        );
    }

    /**
     * @return array<string, string> caminho relativo => caminho absoluto
     */
    protected function tsxFiles(): array
    {
        $root = $this->projectPath('resources/js');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'tsx' && $file->getExtension() !== 'ts') {
                continue;
            }

            $relative = str_replace(
                [$this->projectPath(''), DIRECTORY_SEPARATOR],
                ['', '/'],
                $file->getPathname()
            );

            $files[ltrim($relative, '/')] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }

    protected function assertContrast(string $selector, string $foreground, string $background, float $minimum): void
    {
        $tokens = $this->tokensIn($selector);

        $this->assertArrayHasKey($foreground, $tokens, "Falta o token --{$foreground} em {$selector}.");
        $this->assertArrayHasKey($background, $tokens, "Falta o token --{$background} em {$selector}.");

        $ratio = $this->contrast($tokens[$foreground], $tokens[$background]);

        $this->assertGreaterThanOrEqual(
            $minimum,
            round($ratio, 2),
            sprintf(
                '--%s (%s) sobre --%s (%s) da %.2f:1, abaixo do minimo de %.1f:1.',
                $foreground,
                $tokens[$foreground],
                $background,
                $tokens[$background],
                $ratio,
                $minimum
            )
        );
    }

    /**
     * Extrai os pares token => hex de um bloco do app.css.
     *
     * @return array<string, string>
     */
    protected function tokensIn(string $selector): array
    {
        $pattern = '/^'.preg_quote($selector, '/').'\s*\{(.*?)^\}/ms';

        $this->assertMatchesRegularExpression(
            $pattern,
            $this->css(),
            "Nao encontrei o bloco {$selector} em app.css."
        );

        preg_match($pattern, $this->css(), $block);
        preg_match_all('/--([a-z0-9-]+):\s*(#[0-9A-Fa-f]{6})\s*;/', $block[1], $pairs, PREG_SET_ORDER);

        $tokens = [];

        foreach ($pairs as $pair) {
            $tokens[$pair[1]] = strtoupper($pair[2]);
        }

        return $tokens;
    }

    protected function css(): string
    {
        return file_get_contents($this->projectPath('resources/css/app.css'));
    }

    protected function projectPath(string $relative): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * Luminancia relativa segundo a WCAG 2.1.
     */
    protected function relativeLuminance(string $hex): float
    {
        $channels = array_map(
            static function (string $channel): float {
                $value = hexdec($channel) / 255;

                return $value <= 0.03928
                    ? $value / 12.92
                    : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(ltrim($hex, '#'), 2)
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    protected function contrast(string $foreground, string $background): float
    {
        $a = $this->relativeLuminance($foreground);
        $b = $this->relativeLuminance($background);

        return $a > $b
            ? ($a + 0.05) / ($b + 0.05)
            : ($b + 0.05) / ($a + 0.05);
    }
}
