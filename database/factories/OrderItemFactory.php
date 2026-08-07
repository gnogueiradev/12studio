<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->numberBetween(500, 5000);
        $qty = fake()->numberBetween(1, 3);

        return [
            'order_id' => Order::factory(),
            'variant_id' => null,
            'product_name' => ucfirst(fake()->word().' '.fake()->word()),
            'variant_label' => null,
            'sku' => strtoupper(fake()->bothify('SKU-####-??')),
            'unit_price_cents' => $unitPrice,
            'catalog_unit_price_cents' => $unitPrice,
            'price_override_reason' => null,
            'personalization_surcharge_cents' => 0,
            'qty' => $qty,
            'line_total_cents' => $unitPrice * $qty,
            'vat_rate' => 23,
            'image_url' => null,
            'personalization' => null,
            'fulfillment_mode' => 'in_stock',
            'production_status' => 'not_required',
        ];
    }

    /**
     * Item que exige producao — entra no quadro em `awaiting_production`.
     */
    public function madeToOrder(): static
    {
        return $this->state(fn (array $attributes): array => [
            'fulfillment_mode' => 'made_to_order',
            'production_status' => 'awaiting_production',
        ]);
    }
}
