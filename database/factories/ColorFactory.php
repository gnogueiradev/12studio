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
            /*
             * Duas palavras e nao fake()->colorName(): o nome da cor passou a
             * ser unico GLOBAL, e o dicionario de nomes de cor do Faker tem
             * poucas dezenas de entradas — um teste que criasse trinta cores
             * batia no indice por azar do sorteio. O `unique` vai na segunda
             * palavra, que e o que chega para o nome inteiro nao repetir.
             */
            'name' => ucfirst(fake()->word()).' '.ucfirst(fake()->unique()->word()),
            'hex_color' => fake()->hexColor(),
            'image' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    /**
     * A cor existe nestes filamentos.
     *
     * Explicito de proposito. Ligar a todos os materiais por omissao dava
     * testes verdes que nao provavam nada: a matriz de variantes so filtra o
     * que o catalogo exclui, e uma factory que nunca exclui nada escondia
     * precisamente o comportamento em teste.
     *
     * @param  Material|iterable<int, Material>  $materials
     */
    public function withMaterials(Material|iterable $materials): static
    {
        $ids = collect($materials instanceof Material ? [$materials] : $materials)
            ->map(fn (Material $material): int => $material->id)
            ->all();

        return $this->afterCreating(fn (Color $color) => $color->materials()->sync($ids));
    }
}
