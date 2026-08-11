<?php

namespace Database\Factories;

use App\Models\NotifySubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotifySubscription>
 */
class NotifySubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Em minusculas como o controller grava: uma factory que produzisse
            // "Foo@Bar.pt" deixava passar um teste de duplicados que a
            // aplicacao real apanharia.
            'email' => mb_strtolower(fake()->unique()->safeEmail()),
        ];
    }
}
