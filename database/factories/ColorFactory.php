<?php

namespace Database\Factories;

use App\Models\Color;
use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Color>
 */
class ColorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_id' => Material::factory(),
            'name' => fake()->unique()->colorName(),
            'hex_color' => fake()->hexColor(),
            'image' => null,
            // Null = herda o preco/kg do material (o caso normal).
            'price_per_kg_cents' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
