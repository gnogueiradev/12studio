<?php

namespace App\Services;

use App\Models\Material;
use App\Support\Money;

class MaterialService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): Material
    {
        return Material::query()->create($this->normalizePrice($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Material $material, array $data): Material
    {
        $material->update($this->normalizePrice($data));

        return $material;
    }

    /**
     * Regra global de eliminacao: nunca hard-delete. Um material arquivado
     * sai do seletor da variante, mas as cores e as variantes que ja o usam
     * ficam intactas — a FK `variants.color_id` e restrictOnDelete de
     * proposito.
     */
    public function archive(Material $material): void
    {
        $material->update(['active' => false]);
    }

    /**
     * Euros por kg do formulario -> centimos inteiros.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePrice(array $data): array
    {
        if (array_key_exists('price_per_kg', $data)) {
            $data['price_per_kg_cents'] = Money::fromDecimal((string) $data['price_per_kg']);
            unset($data['price_per_kg']);
        }

        return $data;
    }
}
