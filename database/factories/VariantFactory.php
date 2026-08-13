<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####-??')),
            'color_id' => null,
            'material_id' => null,
            'size_label' => null,
            'price_cents' => fake()->numberBetween(500, 9900),
            'compare_at_cents' => null,
            'stock' => 10,
            'reserved_stock' => 0,
            'low_stock_threshold' => 3,
            'is_default' => false,
            'active' => true,
        ];
    }

    public function stock(int $stock): static
    {
        return $this->state(fn (array $attributes): array => ['stock' => $stock]);
    }

    public function isDefault(): static
    {
        return $this->state(fn (array $attributes): array => ['is_default' => true]);
    }
}
