<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->word();

        return [
            'name' => ucfirst($name),
            'slug' => str($name)->slug()->value(),
            'description' => fake()->optional()->sentence(),
            'status' => 'visible',
            'color' => null,
            'sort_order' => 0,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'hidden']);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'archived']);
    }
}
