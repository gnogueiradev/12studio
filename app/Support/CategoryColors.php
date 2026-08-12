<?php

namespace App\Support;

/**
 * Paleta fixa das categorias.
 *
 * Sete tons e nao um seletor de hex livre: a cor pinta a etiqueta da categoria
 * no backoffice e o menu da loja, e uma cor escolhida a mao entrava sem
 * ninguem verificar se ainda se le sobre o fundo claro E sobre o escuro. Estes
 * sete sao os do design, ja verificados nos dois temas.
 *
 * Vive em Support porque duas camadas a pedem: a validacao dos Form Requests e
 * o teste-guarda que a compara com a lista gemea do frontend
 * (CATEGORY_COLORS em resources/js/types/catalog.ts). Esse teste-guarda e o
 * tests/Unit/CategoryColorsTest.php — referido em texto e nao num @see para
 * nao obrigar uma classe de app a importar uma classe de teste, que so existe
 * no autoload-dev.
 */
class CategoryColors
{
    /**
     * @var array<int, array{hex: string, name: string}>
     */
    public const ALL = [
        ['hex' => '#C6A77B', 'name' => 'Bege'],
        ['hex' => '#B0684A', 'name' => 'Terracota'],
        ['hex' => '#D9A84E', 'name' => 'Dourado'],
        ['hex' => '#8FAE7F', 'name' => 'Verde musgo'],
        ['hex' => '#7C93A9', 'name' => 'Azul pedra'],
        ['hex' => '#A9829C', 'name' => 'Malva'],
        ['hex' => '#7C6B5C', 'name' => 'Neutra'],
    ];

    /**
     * Os hexes soltos, para o Rule::in dos Form Requests.
     *
     * @return array<int, string>
     */
    public static function hexes(): array
    {
        return array_column(self::ALL, 'hex');
    }
}
