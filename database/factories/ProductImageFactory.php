<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'variant_id' => null,
            'color_id' => null,
            'path' => 'products/'.fake()->unique()->uuid().'.jpg',
            'alt' => null,
            'sort_order' => 1,
            // Nao e primaria por omissao: o indice unico parcial so permite
            // uma por produto, e um default true rebentaria a segunda vez.
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes): array => ['is_primary' => true]);
    }
}
