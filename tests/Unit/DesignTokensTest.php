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
