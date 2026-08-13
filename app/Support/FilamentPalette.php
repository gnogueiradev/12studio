<?php

namespace App\Support;

/**
 * Presets de cor de filamento, oferecidos como chips no modal de nova cor.
 *
 * NAO e o catalogo: as cores vivem na base de dados. Isto e o atalho que evita
 * escolher o nome e o tom a mao para as oito que qualquer loja tem — clicar na
 * chip preenche os dois campos, e a partir daí a cor e uma cor como as outras.
 *
 * Vive em Support e nao numa constante do TypeScript porque a prop ja viajava
 * daqui para o modal; mover custava uma constante gemea e um teste a
 * compara-las, por ganho nenhum.
 */
class FilamentPalette
{
    /**
     * @return array<int, array{name: string, hex: string}>
     */
    public static function all(): array
    {
        return [
            ['name' => 'Preto', 'hex' => '#1A1715'],
            ['name' => 'Branco', 'hex' => '#FAF8F5'],
            ['name' => 'Bege', 'hex' => '#C6A77B'],
            ['name' => 'Terracota', 'hex' => '#B0684A'],
            ['name' => 'Verde musgo', 'hex' => '#8FAE7F'],
            ['name' => 'Dourado', 'hex' => '#D9A84E'],
            ['name' => 'Azul pedra', 'hex' => '#7C93A9'],
            ['name' => 'Malva', 'hex' => '#A9829C'],
        ];
    }
}
