<?php

namespace App\Support;

/**
 * Atalhos de cor das categorias.
 *
 * NAO e a lista branca da validacao: a cor da categoria e hex livre, e estes
 * sete sao so os tons do design, oferecidos como atalhos no seletor. Foram a
 * unica escolha possivel enquanto a cor pintava texto e um tom qualquer podia
 * deixar de se ler; hoje pinta uma bolinha decorativa, sem minimo de contraste,
 * e o proprio seletor avisa quando o tom se perde no fundo de algum dos temas.
 * Mesmo papel que o FilamentPalette faz para as cores de filamento.
 *
 * Vive em Support porque duas camadas a pedem: o seletor, atraves da lista
 * gemea do frontend (CATEGORY_COLORS em resources/js/types/catalog.ts), e o
 * teste-guarda que obriga as duas a concordarem —
 * tests/Unit/CategoryColorsTest.php, referido em texto e nao num @see para nao
 * obrigar uma classe de app a importar uma classe de teste, que so existe no
 * autoload-dev.
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
     * Os hexes soltos, para o teste-guarda verificar que nao ha dois iguais —
     * dois atalhos com o mesmo tom eram um deles impossivel de escolher.
     *
     * @return array<int, string>
     */
    public static function hexes(): array
    {
        return array_column(self::ALL, 'hex');
    }
}
