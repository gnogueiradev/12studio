<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Que combinacoes de Cor x Material x Tamanho e que o dono consegue mesmo
 * imprimir.
 *
 * A matriz do modal de novo produto era um produto cartesiano cego: escolhidas
 * duas cores e dois materiais, nasciam quatro variantes. Mas nem todas as cores
 * existem em todos os filamentos — ha rosa em PLA e em Matte, nao ha rosa em
 * Silk — e a quarta variante era impossivel de produzir. A pivo `color_material`
 * diz o que existe, e isto transforma o cruzamento numa INTERSECCAO.
 *
 * Vive em Support, ao lado do VariantSku, porque tres sitios fazem a mesma
 * pergunta: a geracao em bloco (ProductService), a validacao que recusa a
 * submissao sem par nenhum, e o formulario da variante avulsa. O `combos()` nao
 * toca na base de dados de proposito — recebe o catalogo ja lido e so decide,
 * o que o torna testavel sozinho.
 */
class ColorMaterialMatrix
{
    /**
     * O catalogo destas cores: em que materiais e que cada uma existe.
     *
     * Consulta directa a pivo em vez de `Color::with('materials')`: so se
     * querem os ids, e hidratar os models inteiros para os deitar fora a seguir
     * era trabalho a mais dentro da transaccao de criacao do produto.
     *
     * Uma cor sem linhas na pivo simplesmente nao aparece no resultado — quem
     * ler tem de tratar a ausencia como lista vazia, que e o que o `combos()`
     * faz.
     *
     * @param  array<int, int>  $colorIds
     * @return array<int, array<int, int>> colorId => materialIds[]
     */
    public static function availableFor(array $colorIds): array
    {
        if ($colorIds === []) {
            return [];
        }

        $pairs = DB::table('color_material')
            ->whereIn('color_id', $colorIds)
            ->get(['color_id', 'material_id']);

        $available = [];

        foreach ($pairs as $pair) {
            $available[(int) $pair->color_id][] = (int) $pair->material_id;
        }

        return $available;
    }

    /**
     * A matriz que sobrevive ao catalogo.
     *
     * A ordem dos ciclos — cor por fora, material no meio, tamanho por dentro —
     * e a mesma da pre-visualizacao em product-create-dialog.tsx. Se uma mudar
     * sem a outra, a matriz que o admin viu deixa de ser a que se gravou.
     *
     * O tamanho e opcional, e o `[null]` e o que faz o ciclo de dentro degenerar
     * nesse caso sem precisar de um segundo ramo.
     *
     * @param  array<int, int>  $colorIds
     * @param  array<int, int>  $materialIds
     * @param  array<int, string>  $sizes
     * @param  array<int, array<int, int>>  $available
     * @return array<int, array{color_id: int, material_id: int, size_label: string|null}>
     */
    public static function combos(array $colorIds, array $materialIds, array $sizes, array $available): array
    {
        if ($colorIds === [] || $materialIds === []) {
            return [];
        }

        $sizes = $sizes === [] ? [null] : $sizes;

        $combos = [];

        foreach ($colorIds as $colorId) {
            foreach ($materialIds as $materialId) {
                if (! in_array($materialId, $available[$colorId] ?? [], true)) {
                    continue;
                }

                foreach ($sizes as $size) {
                    $combos[] = [
                        'color_id' => $colorId,
                        'material_id' => $materialId,
                        'size_label' => $size,
                    ];
                }
            }
        }

        return $combos;
    }

    /**
     * Sobra alguma coisa? Para a validacao e para a UI, que so querem saber se
     * ha matriz — nao a matriz.
     *
     * O tamanho nao entra: multiplicar nao cria nem destroi pares, e um produto
     * sem tamanhos tem exactamente os mesmos pares possiveis.
     *
     * @param  array<int, int>  $colorIds
     * @param  array<int, int>  $materialIds
     * @param  array<int, array<int, int>>  $available
     */
    public static function hasAny(array $colorIds, array $materialIds, array $available): bool
    {
        foreach ($colorIds as $colorId) {
            foreach ($materialIds as $materialId) {
                if (in_array($materialId, $available[$colorId] ?? [], true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
