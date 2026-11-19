<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'variant_id' => Variant::factory(),
            'qty' => 1,
            'personalization' => null,
            'personalization_hash' => CartItem::hashFor([]),
        ];
    }

    /**
     * @param  array<string, string>  $personalization
     */
    public function personalised(array $personalization): static
    {
        return $this->state(fn (array $attributes): array => [
            'personalization' => $personalization,
            'personalization_hash' => CartItem::hashFor($personalization),
        ]);
    }
}
