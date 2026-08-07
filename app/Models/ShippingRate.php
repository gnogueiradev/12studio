<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int $rate_cents
 * @property int|null $free_above_cents
 * @property int|null $estimated_days_min
 * @property int|null $estimated_days_max
 * @property bool $active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'rate_cents', 'free_above_cents', 'estimated_days_min',
    'estimated_days_max', 'active', 'sort_order',
])]
class ShippingRate extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_cents' => 'integer',
            'free_above_cents' => 'integer',
            'estimated_days_min' => 'integer',
            'estimated_days_max' => 'integer',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Portes efetivos para um subtotal (com desconto ja aplicado): gratis
     * quando atinge o limiar free_above_cents.
     */
    public function effectiveRateCents(int $subtotalCents): int
    {
        if ($this->free_above_cents !== null && $subtotalCents >= $this->free_above_cents) {
            return 0;
        }

        return $this->rate_cents;
    }
}
