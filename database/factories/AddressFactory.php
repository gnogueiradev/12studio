<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'line1' => fake()->streetAddress(),
            'line2' => null,
            // Formato PT: NNNN-NNN.
            'postal_code' => fake()->numerify('####-###'),
            'city' => fake()->city(),
            'country' => 'PT',
            'phone' => fake()->numerify('9########'),
            'nif' => fake()->numerify('#########'),
            'is_default' => true,
        ];
    }
}
