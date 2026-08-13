<?php

namespace App\Services;

use App\Models\Color;

/**
 * CRUD de cores.
 *
 * Foi um servico grande enquanto uma cor pertenceu a um material: cada gravacao
 * era um leque de linhas, uma por material, e o grupo resolvia-se pelo nome
 * porque nao havia coluna a liga-las. Nada disso existe — uma cor e uma linha.
 *
 * O que sobra de nao-trivial e o restauro em store(): ver o comentario la.
 */
class ColorService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): Color
    {
        /*
         * Restaurar em vez de inserir quando ja la esta uma arquivada com o
         * mesmo nome. O indice colors_name_unique e absoluto — nao sabe de
         * `is_active` —, e o StoreColorRequest deixa passar de proposito um
         * nome que so esta ocupado por uma cor arquivada. Sem este ramo, essa
         * gravacao batia no indice e devolvia um erro de base de dados que
         * ninguem consegue explicar a quem esta a preencher o formulario.
         */
        $existing = Color::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) ($data['name'] ?? '')))])
            ->first();

        if ($existing !== null) {
            $existing->update([...$data, 'is_active' => true]);

            return $existing;
        }

        return Color::query()->create([
            ...$data,
            'is_active' => true,
            'sort_order' => $data['sort_order'] ?? $this->nextSortOrder(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Color $color, array $data): Color
    {
        $color->update($data);

        return $color;
    }

    /**
     * Regra global de eliminacao: nunca hard-delete. Uma cor arquivada sai dos
     * seletores mas continua agarrada as variantes que ja a usam — a FK
     * `variants.color_id` e restrictOnDelete de proposito.
     */
    public function archive(Color $color): void
    {
        $color->update(['is_active' => false]);
    }

    public function restore(Color $color): void
    {
        $color->update(['is_active' => true]);
    }

    /**
     * Uma cor nova entra a seguir a ultima, em vez de empatar com ela em zero.
     */
    private function nextSortOrder(): int
    {
        return (int) Color::query()->max('sort_order') + 1;
    }
}
