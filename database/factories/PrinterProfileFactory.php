<?php

namespace Database\Factories;

use App\Models\PrinterProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrinterProfile>
 */
class PrinterProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // `printer_profiles.name` e unico e a lista de impressoras reais e
            // curta demais para sortear sem colidir — mesmo raciocinio do
            // MaterialFactory. Quem precisa de "Bambu Lab A1" passa-o no create().
            'name' => str(fake()->unique()->word())->title()->value(),
            // Os quatro numeros a volta de uma maquina de consumo real (uma A1
            // e 145 W, 400 EUR, 4000 h, 0,04 EUR/h). Os intervalos importam:
            // uma fabrica calibrada para maquinas industriais dava a qualquer
            // teste futuro escrito com o valor por omissao precos varias vezes
            // acima dos reais, sem nada a dize-lo.
            'average_power_watts' => fake()->numberBetween(80, 200),
            'purchase_price_cents' => fake()->numberBetween(20_000, 120_000),
            'lifetime_hours' => fake()->numberBetween(3_000, 6_000),
            'maintenance_micros_per_hour' => fake()->numberBetween(20_000, 80_000),
            'notes' => null,
            // Predefinida NAO por omissao: o indice unico parcial so deixa
            // existir uma, e uma fabrica que a marcasse rebentava ao segundo
            // create(). Quem a quer pede o state isDefault().
            'is_default' => false,
            'active' => true,
            'sort_order' => 0,
        ];
    }

    public function isDefault(): static
    {
        return $this->state(fn (array $attributes): array => ['is_default' => true]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
            'is_default' => false,
        ]);
    }
}
