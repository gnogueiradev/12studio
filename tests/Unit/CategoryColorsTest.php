<?php

namespace Tests\Unit;

use App\Support\CategoryColors;
use PHPUnit\Framework\TestCase;

/**
 * Teste-guarda da paleta das categorias (mesma tecnica do DesignTokensTest:
 * PHP a ler o ficheiro do frontend).
 *
 * A paleta existe duas vezes de proposito — o PHP valida o que entra, o React
 * desenha os botoes — e nada obriga as duas listas a concordarem. Quando
 * divergem, a avaria e silenciosa e do pior tipo: o seletor mostra uma cor que
 * o servidor recusa, e quem esta a criar a categoria ve um erro de validacao
 * numa cor que lhe foi oferecida.
 */
class CategoryColorsTest extends TestCase
{
    public function test_the_frontend_palette_matches_the_backend_one(): void
    {
        $this->assertSame(CategoryColors::ALL, $this->frontendPalette());
    }

    public function test_the_palette_has_no_duplicate_hexes(): void
    {
        $hexes = CategoryColors::hexes();

        $this->assertSame(
            $hexes,
            array_values(array_unique($hexes)),
            'Ha dois tons iguais na paleta: um deles fica impossivel de escolher no seletor.'
        );
    }

    /**
     * Le o CATEGORY_COLORS do catalog.ts e devolve-o na mesma forma do
     * CategoryColors::ALL, para o assertSame comparar hex, nome e ordem de uma
     * vez.
     *
     * @return array<int, array{hex: string, name: string}>
     */
    private function frontendPalette(): array
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, 'resources/js/types/catalog.ts')
        );

        $this->assertMatchesRegularExpression(
            '/export const CATEGORY_COLORS = \[(.*?)\] as const;/s',
            $source,
            'Nao encontrei o CATEGORY_COLORS em resources/js/types/catalog.ts.'
        );

        preg_match('/export const CATEGORY_COLORS = \[(.*?)\] as const;/s', $source, $block);

        preg_match_all(
            "/\{\s*hex:\s*'(#[0-9A-Fa-f]{6})',\s*name:\s*'([^']+)',?\s*\}/",
            $block[1],
            $entries,
            PREG_SET_ORDER
        );

        return array_map(
            static fn (array $entry): array => ['hex' => $entry[1], 'name' => $entry[2]],
            $entries
        );
    }
}
