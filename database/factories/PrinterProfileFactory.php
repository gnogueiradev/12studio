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
            // A volta dos 0,20 EUR/h da impressora da casa. O intervalo importa:
            // uma fabrica calibrada para o mundo antigo (0,40-0,90 EUR/h) dava
            // a qualquer teste futuro escrito com o valor por omissao precos
            // varias vezes acima dos reais, sem nada a dize-lo.
            'hourly_rate_cents' => fake()->numberBetween(15, 35),
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
