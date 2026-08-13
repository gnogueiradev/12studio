<?php

namespace App\Services;

use App\Models\Color;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Escreve cores em leque.
 *
 * O backoffice gere uma cor como uma so identidade ("Terracota"), mas a tabela
 * guarda uma linha por par cor x material — o indice colors_material_name_unique
 * e o que impede vender combinacoes que nao existem. Cada gravacao daqui e por
 * isso um leque: um nome, um hex e um preco espalhados pelas linhas dos
 * materiais escolhidos, tudo na mesma transacao.
 *
 * O grupo resolve-se pelo NOME. Nao ha coluna a liga-los porque nao ha nada a
 * ligar: a cor e o nome, e as linhas sao onde ela existe.
 */
class ColorService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function storeGroup(array $data): void
    {
        $materialIds = $this->pullMaterialIds($data);
        $attributes = $this->normalizePrice($data);

        DB::transaction(function () use ($materialIds, $attributes): void {
            foreach ($materialIds as $materialId) {
                $this->writeRow($materialId, $attributes);
            }
        });
    }

    /**
     * `$color` e o representante do grupo, nao o alvo: o que se edita sao todas
     * as linhas com o mesmo nome.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateGroup(Color $color, array $data): void
    {
        $materialIds = $this->pullMaterialIds($data);
        $attributes = $this->normalizePrice($data);
        $group = $this->group($color);

        DB::transaction(function () use ($group, $materialIds, $attributes): void {
            foreach ($group as $row) {
                /*
                 * Tirar um material do grupo ARQUIVA a linha, nunca a apaga: a
                 * FK `variants.color_id` e restrictOnDelete de proposito, e as
                 * variantes que ja a usam tem de continuar a resolver a cor.
                 *
                 * O nome novo vai tambem para as linhas que sairam — a cor foi
                 * renomeada, nao dividida em duas. E e o que faz o material
                 * voltar a entrar no grupo (em vez de colidir com ele) se
                 * alguem o reactivar mais tarde.
                 */
                $row->update([
                    ...$attributes,
                    'is_active' => in_array($row->material_id, $materialIds, true),
                ]);
            }

            $existing = $group->pluck('material_id')->all();

            foreach (array_diff($materialIds, $existing) as $materialId) {
                $this->writeRow($materialId, $attributes);
            }
        });
    }

    /**
     * Regra global de eliminacao: nunca hard-delete. Uma cor arquivada sai dos
     * seletores mas continua agarrada as variantes que ja a usam.
     */
    public function archiveGroup(Color $color): void
    {
        $this->setGroupActive($color, false);
    }

    public function restoreGroup(Color $color): void
    {
        $this->setGroupActive($color, true);
    }

    /**
     * Uma linha do grupo num material.
     *
     * Restaura em vez de inserir quando ja la esta uma arquivada com o mesmo
     * nome. Sem isto, re-adicionar um material de onde a cor tinha sido tirada
     * batia no colors_material_name_unique e devolvia um erro de base de dados
     * que ninguem consegue explicar a quem esta a preencher o formulario.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function writeRow(int $materialId, array $attributes): void
    {
        $existing = Color::query()
            ->where('material_id', $materialId)
            ->where('name', $attributes['name'])
            ->first();

        if ($existing !== null) {
            $existing->update([...$attributes, 'is_active' => true]);

            return;
        }

        Color::query()->create([
            ...$attributes,
            'material_id' => $materialId,
            'is_active' => true,
            'sort_order' => $this->nextSortOrder($materialId),
        ]);
    }

    /**
     * As cores da paleta nascem com o material em 0..n (MaterialService), por
     * isso uma cor nova entra a seguir a ultima em vez de empatar com ela.
     */
    private function nextSortOrder(int $materialId): int
    {
        return (int) Color::query()->where('material_id', $materialId)->max('sort_order') + 1;
    }

    /** @return Collection<int, Color> */
    private function group(Color $color): Collection
    {
        return Color::query()->where('name', $color->name)->get();
    }

    private function setGroupActive(Color $color, bool $active): void
    {
        Color::query()
            ->where('name', $color->name)
            ->update(['is_active' => $active]);
    }

    /**
     * `material_ids` vem do formulario mas nao e coluna de `colors` — sai do
     * payload antes do create(), como o MaterialService faz com as cores.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, int>
     */
    private function pullMaterialIds(array &$data): array
    {
        $ids = $data['material_ids'] ?? [];
        unset($data['material_ids']);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * O preco/kg da cor e um OVERRIDE opcional: vazio significa "herda do
     * material", nao "zero" — por isso null, nunca 0.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePrice(array $data): array
    {
        if (array_key_exists('price_per_kg', $data)) {
            $value = $data['price_per_kg'];
            $data['price_per_kg_cents'] = ($value === null || $value === '')
                ? null
                : Money::fromDecimal((string) $value);
            unset($data['price_per_kg']);
        }

        return $data;
    }
}
