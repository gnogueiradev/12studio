<?php

namespace App\Support;

use App\Models\Color;
use Illuminate\Database\Eloquent\Builder;

/**
 * As cores disponiveis, em lista plana.
 *
 * Vive em Support porque tres sitios a pedem: o formulario da variante avulsa,
 * o modal de novo produto (que gera a matriz de variantes a partir dela) e a
 * listagem de produtos. Duplicar a consulta eram tres oportunidades de as
 * regras de arquivamento divergirem.
 *
 * Substitui o antigo ColorGroups, que devolvia as cores agrupadas por material.
 * O agrupamento deixou de fazer sentido — e a bobine que se escolhe a parte.
 */
class ColorOptions
{
    /**
     * As arquivadas ficam de fora — excepto as que as variantes ja usam
     * (`$keep`), senao o seletor abria vazio e uma gravacao inocente perdia a
     * cor.
     *
     * `$keep` aceita uma lista e nao so um id porque a listagem de produtos
     * serve o modal inteiro de uma vez: as variantes que ele mostra podem usar
     * varias cores arquivadas, e nao ha "a" variante em edicao quando a pagina
     * e construida.
     *
     * @param  int|array<int, int|null>|null  $keep
     * @return array<int, array{id: int, name: string, hex: string}>
     */
    public static function all(int|array|null $keep = null): array
    {
        $keepIds = array_values(array_filter(
            is_array($keep) ? $keep : [$keep],
            fn (?int $id): bool => $id !== null,
        ));

        return Color::query()
            ->where(function (Builder $query) use ($keepIds): void {
                $query->where('is_active', true);

                if ($keepIds !== []) {
                    $query->orWhereIn('id', $keepIds);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Color $color): array => [
                'id' => $color->id,
                'name' => $color->name,
                'hex' => $color->hex_color,
            ])
            ->all();
    }
}
