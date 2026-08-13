<?php

namespace Database\Factories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        // Str::slug e nao Slug::unique: a unicidade e por ambito, e o sufixo
        // numerico do Slug::unique olharia para a tabela toda — a mesma palavra
        // em produtos e em clientes sairia daqui como "natal" e "natal-2".
        return [
            'scope' => Tag::SCOPE_PRODUCT,
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }

    public function customer(): static
    {
        return $this->state(fn (): array => ['scope' => Tag::SCOPE_CUSTOMER]);
    }

    public function order(): static
    {
        return $this->state(fn (): array => ['scope' => Tag::SCOPE_ORDER]);
    }
}
