<?php

namespace App\Support;

use App\Models\Color;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cores por material, para os seletores agrupados.
 *
 * Vive em Support porque dois sitios a pedem: o formulario da variante avulsa
 * (VariantController) e o modal de novo produto, que gera a matriz de variantes
 * a partir desta mesma paleta. Duplicar a consulta era duas oportunidades de as
 * regras de arquivamento divergirem.
 */
class ColorGroups
{
    /**
     * Materiais e cores arquivados ficam de fora — excepto o que a variante ja
     * usa (`$keepColorId`), senao o seletor abria vazio e uma gravacao inocente
     * perdia a cor.
     *
     * @return array<int, array{material: string, colors: array<int, array{id: int, name: string, hex: string, pricePerKgCents: int}>}>
     */
    public static function all(?int $keepColorId = null): array
    {
        return Color::query()
            ->with('material')
            ->where(function (Builder $query) use ($keepColorId): void {
                $query
                    ->where('is_active', true)
                    ->whereHas('material', fn (Builder $material) => $material->where('active', true));

                if ($keepColorId !== null) {
                    $query->orWhere('id', $keepColorId);
                }
            })
            ->get()
            // Ordenar e agrupar em memoria: sao dezenas de linhas, e ordenar
            // pelo sort_order do MATERIAL em SQL obrigava a um join so para
            // isto.
            ->sortBy([
                fn (Color $a, Color $b): int => $a->material->sort_order <=> $b->material->sort_order,
                fn (Color $a, Color $b): int => $a->material->name <=> $b->material->name,
                fn (Color $a, Color $b): int => $a->sort_order <=> $b->sort_order,
                fn (Color $a, Color $b): int => $a->name <=> $b->name,
            ])
            ->groupBy(fn (Color $color): string => $color->material->name)
            ->map(fn ($colors, string $material): array => [
                'material' => $material,
                'colors' => $colors
                    ->map(fn (Color $color): array => [
                        'id' => $color->id,
                        'name' => $color->name,
                        'hex' => $color->hex_color,
                        // O modal de novo produto estima a margem com o preco/kg
                        // real da cor escolhida, em vez de um valor fixo.
                        'pricePerKgCents' => $color->effectivePricePerKgCents(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
