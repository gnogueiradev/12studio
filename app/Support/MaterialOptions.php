<?php

namespace App\Support;

use App\Models\Material;
use Illuminate\Database\Eloquent\Builder;

/**
 * Os materiais disponiveis, em lista plana.
 *
 * Gemea do ColorOptions e pelo mesmo motivo: cor e material sao eixos
 * independentes e os mesmos formularios pedem os dois. Traz o preco/kg porque e
 * ele que a calculadora e a estimativa do modal de produto usam — a cor deixou
 * de ter preco, e a bobine e quem custa dinheiro.
 */
class MaterialOptions
{
    /**
     * Os arquivados ficam de fora — excepto os que as variantes ja usam
     * (`$keep`), senao o seletor abria vazio e uma gravacao inocente perdia o
     * material. Aceita uma lista pela mesma razao que o ColorOptions.
     *
     * @param  int|array<int, int|null>|null  $keep
     * @return array<int, array{id: int, name: string, family: string|null, pricePerKgCents: int}>
     */
    public static function all(int|array|null $keep = null): array
    {
        $keepIds = array_values(array_filter(
            is_array($keep) ? $keep : [$keep],
            fn (?int $id): bool => $id !== null,
        ));

        return Material::query()
            ->where(function (Builder $query) use ($keepIds): void {
                $query->where('active', true);

                if ($keepIds !== []) {
                    $query->orWhereIn('id', $keepIds);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Material $material): array => [
                'id' => $material->id,
                'name' => $material->name,
                'family' => $material->family,
                'pricePerKgCents' => $material->price_per_kg_cents,
            ])
            ->all();
    }
}
