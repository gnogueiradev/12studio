<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->word().' '.fake()->word();

        return [
            'category_id' => null,
            'name' => ucfirst($name),
            'slug' => str($name)->slug()->value(),
            'description' => fake()->optional()->paragraph(),
            'status' => 'active',
            'featured' => false,
            'vat_rate' => 23,
            'fulfillment_mode' => 'in_stock',
            'production_time_days' => null,
            'allow_backorder' => false,
            'max_open_production_qty' => null,
        ];
    }

    /**
     * Produzido por encomenda: os itens entram no quadro de producao.
     */
    public function madeToOrder(): static
    {
        return $this->state(fn (array $attributes): array => [
            'fulfillment_mode' => 'made_to_order',
            'production_time_days' => 5,
        ]);
    }

    public function custom(): static
    {
        return $this->state(fn (array $attributes): array => [
            'fulfillment_mode' => 'custom',
            'production_time_days' => 7,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'archived']);
    }
}
