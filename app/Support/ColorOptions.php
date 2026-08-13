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
     * As arquivadas ficam de fora — excepto a que a variante ja usa
     * (`$keepColorId`), senao o seletor abria vazio e uma gravacao inocente
     * perdia a cor.
     *
     * @return array<int, array{id: int, name: string, hex: string}>
     */
    public static function all(?int $keepColorId = null): array
    {
        return Color::query()
            ->where(function (Builder $query) use ($keepColorId): void {
                $query->where('is_active', true);

                if ($keepColorId !== null) {
                    $query->orWhere('id', $keepColorId);
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
