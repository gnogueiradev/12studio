<?php

namespace App\Services;

use App\Models\Color;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

/**
 * CRUD de cores.
 *
 * Foi um servico grande enquanto uma cor pertenceu a um material: cada gravacao
 * era um leque de linhas, uma por material, e o grupo resolvia-se pelo nome
 * porque nao havia coluna a liga-las. Nada disso existe — uma cor e uma linha.
 *
 * O que sobra de nao-trivial sao dois sitios: o restauro em store(), e o
 * syncMaterials(), que e o unico ponto do sistema que esconde variantes por
 * decisao do catalogo e nao do dono.
 */
class ColorService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): Color
    {
        $data = $this->withoutMaterialIds($data);

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
        $color->update($this->withoutMaterialIds($data));

        return $color;
    }

    /**
     * Em que filamentos e que esta cor existe — e o que isso faz as variantes
     * que ja la estao.
     *
     * Unico ponto do sistema que esconde uma variante por decisao do CATALOGO e
     * nao do dono. Devolve as contas para quem chama poder dizer o que fez: uma
     * gravacao de cor que faz desaparecer meia duzia de variantes da loja tem de
     * o anunciar.
     *
     * @param  array<int, int>  $materialIds
     * @return array{hidden: int, restored: int}
     */
    public function syncMaterials(Color $color, array $materialIds): array
    {
        return DB::transaction(function () use ($color, $materialIds): array {
            $color->materials()->sync($materialIds);
            $color->unsetRelation('materials');

            /*
             * Conjunto vazio nao e "esta cor nao existe em filamento nenhum" —
             * e "ainda nao disse em quais". Nao ter declarado nao autoriza a
             * esconder o que ja se vendia; para destruir trabalho feito e
             * preciso uma declaracao que exclua o par, nao a ausencia dela.
             *
             * O outro lado da moeda esta no ColorMaterialMatrix: la, a mesma
             * ausencia impede a CRIACAO, porque para criar e preciso saber em
             * que filamento imprimir. As duas erram para o lado de nao agir sem
             * prova.
             */
            if ($materialIds === []) {
                return ['hidden' => 0, 'restored' => 0];
            }

            $hidden = Variant::query()
                ->where('color_id', $color->id)
                ->whereNotNull('material_id')
                ->whereNotIn('material_id', $materialIds)
                ->where('active', true)
                ->update(['active' => false, 'hidden_by_palette' => true]);

            /*
             * So volta o que o catalogo escondeu. Uma variante que o dono
             * desactivou a mao tem `hidden_by_palette` a false e fica como
             * esta — remarcar o Silk nao pode ressuscitar o que ele nao quer
             * vender.
             */
            $restored = Variant::query()
                ->where('color_id', $color->id)
                ->whereIn('material_id', $materialIds)
                ->where('hidden_by_palette', true)
                ->update(['active' => true, 'hidden_by_palette' => false]);

            return ['hidden' => $hidden, 'restored' => $restored];
        });
    }

    /**
     * `material_ids` vem no mesmo payload do formulario mas nao e coluna de
     * `colors` — vive na pivo. Deixa-lo passar para um `update()`/`create()`
     * rebentava com "column not found" a meio de uma gravacao inocente.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutMaterialIds(array $data): array
    {
        unset($data['material_ids']);

        return $data;
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
