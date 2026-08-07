<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(1000, 20000);
        $shipping = 350;

        return [
            // Os testes que exercitam a sequencia real usam o OrderService;
            // aqui basta um numero unico e plausivel.
            'order_number' => now()->year.'-'.fake()->unique()->numerify('####'),
            'user_id' => null,
            'customer_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->numerify('9########'),
            'nif' => null,
            'status' => 'pending_payment',
            'payment_method' => 'bank_transfer',
            'payment_status' => 'pending',
            'sales_channel' => 'manual',
            'subtotal_cents' => $subtotal,
            'shipping_cents' => $shipping,
            'total_cents' => $subtotal + $shipping,
            'currency' => 'EUR',
            'shipping_address' => null,
            'billing_address' => null,
            'stock_issue' => false,
            'guest_access_token' => Str::random(64),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_status' => 'paid',
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    public function channel(string $channel): static
    {
        return $this->state(fn (array $attributes): array => [
            'sales_channel' => $channel,
        ]);
    }
}
