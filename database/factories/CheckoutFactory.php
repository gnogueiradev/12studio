<?php

namespace Database\Factories;

use App\Models\Checkout;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Checkout>
 */
class CheckoutFactory extends Factory
{
    /**
     * Uma compra de um vaso a 15 € com portes, ainda por pagar.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();
        $phone = fake()->numerify('9########');

        return [
            'token' => Str::random(64),
            'cart_id' => null,
            'order_id' => null,
            'items_snapshot' => [[
                'variant_id' => null,
                'product_name' => 'Vaso',
                'variant_label' => null,
                'sku' => 'VASO',
                'qty' => 1,
                'unit_price_cents' => 1500,
                'personalization_surcharge_cents' => 0,
                'line_total_cents' => 1500,
                'vat_rate' => 23,
                'personalization' => null,
                'fulfillment_mode' => 'in_stock',
                'image_url' => null,
            ]],
            'customer' => ['name' => $name, 'email' => fake()->safeEmail(), 'phone' => $phone, 'nif' => null],
            'shipping_address' => [
                'name' => $name,
                'line1' => fake()->streetAddress(),
                'line2' => null,
                'postalCode' => fake()->numerify('####-###'),
                'city' => fake()->city(),
                'country' => 'PT',
                'phone' => $phone,
            ],
            'subtotal_cents' => 1500,
            'shipping_cents' => 450,
            'discount_cents' => 0,
            'promo_code_id' => null,
            'promo_code' => null,
            'total_cents' => 1950,
            'locale' => 'pt',
            'stripe_session_id' => null,
            'status' => 'open',
            'expires_at' => now()->addHour(),
        ];
    }
}
