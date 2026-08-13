<?php

namespace App\Support;

use App\Models\Color;

/**
 * As cores do catalogo vistas como uma lista de nomes — a camada de cima que o
 * backoffice ja mostra em /admin/cores.
 *
 * Na base de dados uma Color pertence a UM material: "PLA Preto" e "PETG Preto"
 * sao linhas distintas. Aqui interessa a cor, nao o par: "Preto" e uma entrada
 * so, com um hex so.
 *
 * Vive em Support porque dois sitios a pedem, e tem de ser a mesma resposta nos
 * dois: as chips do modal de novo material, e o servico que resolve nome e hex
 * ao gravar o que elas mandam.
 */
class ColorPalette
{
    /**
     * @return array<int, array{name: string, hex: string}>
     */
    public static function all(): array
    {
        $colors = Color::query()
            ->where('is_active', true)
            // O hex do grupo e o da linha mais antiga. E o unico criterio que
            // nao muda quando se acrescenta, reordena ou arquiva um material —
            // o mesmo motivo por que o ColorController escolhe o min(id) para
            // representante do grupo.
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'hex_color'])
            ->groupBy('name')
            ->map(fn ($rows, string $name): array => [
                'name' => $name,
                'hex' => (string) $rows->first()->hex_color,
            ])
            ->values()
            ->all();

        // Instalacao nova: sem uma unica cor o modal abria sem chip nenhuma, e
        // ele exige pelo menos uma para criar o material. Os presets sao a rede
        // que deixa nascer o primeiro — e desaparecem assim que exista uma cor
        // a serio.
        return $colors === [] ? FilamentPalette::all() : $colors;
    }

    /**
     * Nome e hex definitivos das cores escolhidas no formulario.
     *
     * Cada escolhida traz um hex, porque o modal tambem deixa inventar uma cor
     * ali mesmo. Mas esse hex so vale para nomes NOVOS: se a cor ja existe,
     * ganha o hex do catalogo. Senao criar um material com "Preto" a branco
     * partia o grupo que /admin/cores mostra como uma cor so.
     *
     * Pela mesma razao a comparacao ignora maiusculas e o que sai e o nome
     * canonico: quem escreve "preto" esta a falar do "Preto" que ja la esta.
     *
     * Uma chamada para a lista toda, e nao uma por cor: dentro da transacao que
     * grava, a paleta muda a cada linha inserida, e a segunda cor via um
     * catalogo diferente da primeira.
     *
     * @param  array<int, array{name: string, hex: string}>  $chosen
     * @return array<int, array{name: string, hex: string}>
     */
    public static function resolve(array $chosen): array
    {
        $catalog = [];

        foreach (self::all() as $color) {
            $catalog[mb_strtolower($color['name'])] = $color;
        }

        return array_values(array_map(
            fn (array $color): array => $catalog[mb_strtolower(trim($color['name']))] ?? [
                'name' => trim($color['name']),
                'hex' => $color['hex'],
            ],
            $chosen,
        ));
    }
}
