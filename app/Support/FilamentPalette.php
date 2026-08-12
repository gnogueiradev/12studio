<?php

namespace App\Support;

/**
 * Paleta de cores de filamento oferecida ao criar um material.
 *
 * Vive em Support pelo mesmo motivo que o ColorGroups: dois sitios a pedem — a
 * validacao do StoreMaterialRequest (que so aceita nomes desta lista) e as
 * chips do modal de novo material. Duplica-la eram duas oportunidades de o hex
 * gravado deixar de ser o hex mostrado.
 *
 * Note-se que isto NAO e a lista de cores do catalogo: uma Color pertence a um
 * Material e vive na base de dados. Isto sao presets, o atalho que evita criar
 * o material e a seguir ir criar "Preto" e "Branco" a mao.
 */
class FilamentPalette
{
    /**
     * A ordem e a que aparece nas chips e a que vai para o `sort_order` das
     * cores criadas.
     *
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

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_column(self::all(), 'name');
    }

    /**
     * Hex do preset, ou null se o nome nao pertencer a paleta. O
     * MaterialService usa isto para nunca gravar uma cor sem hex conhecido.
     */
    public static function hex(string $name): ?string
    {
        return array_column(self::all(), 'hex', 'name')[$name] ?? null;
    }
}
