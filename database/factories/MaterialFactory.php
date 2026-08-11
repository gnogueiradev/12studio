<?php

namespace Database\Factories;

use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // `materials.name` e unico. Sortear de uma lista fixa esgotava-se
            // (e colidia com testes que fixam um nome a mao), por isso o nome
            // sai de um espaco aberto — quem precisa de um nome concreto
            // passa-o no create().
            'name' => str(fake()->unique()->word())->title()->value(),
            'price_per_kg_cents' => fake()->numberBetween(1500, 3500),
            'active' => true,
            'sort_order' => 0,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['active' => false]);
    }
}
