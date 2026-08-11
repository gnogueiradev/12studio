<?php

namespace App\Services;

use App\Models\Color;
use App\Support\Money;

class ColorService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): Color
    {
        return Color::query()->create($this->normalizePrice($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Color $color, array $data): Color
    {
        $color->update($this->normalizePrice($data));

        return $color;
    }

    /**
     * Regra global de eliminacao: nunca hard-delete. Uma cor arquivada sai
     * do seletor mas continua agarrada as variantes que ja a usam.
     */
    public function archive(Color $color): void
    {
        $color->update(['is_active' => false]);
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
