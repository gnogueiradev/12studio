<?php

namespace App\Models;

use Database\Factories\VariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property string $sku
 * @property int|null $color_id
 * @property string|null $size_label
 * @property int $price_cents
 * @property int|null $compare_at_cents
 * @property int $stock
 * @property int $reserved_stock
 * @property int $low_stock_threshold
 * @property bool $is_default
 * @property bool $active
 * @property int|null $filament_weight_grams
 * @property int|null $printing_time_minutes
 * @property int|null $labor_minutes
 * @property int|null $packaging_cost_cents
 * @property int|null $energy_cost_cents
 * @property int|null $failure_rate_percent
 * @property int|null $product_weight_grams
 * @property int|null $package_weight_grams
 * @property int|null $length_mm
 * @property int|null $width_mm
 * @property int|null $height_mm
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $available_stock
 * @property-read Product $product
 * @property-read Color|null $color
 */
#[Fillable([
    'product_id', 'sku', 'color_id', 'size_label', 'price_cents',
    'compare_at_cents', 'stock', 'reserved_stock', 'low_stock_threshold',
    'is_default', 'active', 'filament_weight_grams', 'printing_time_minutes',
    'labor_minutes', 'packaging_cost_cents', 'energy_cost_cents',
    'failure_rate_percent', 'product_weight_grams', 'package_weight_grams',
    'length_mm', 'width_mm', 'height_mm',
])]
class Variant extends Model
{
    /** @use HasFactory<VariantFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'compare_at_cents' => 'integer',
            'stock' => 'integer',
            'reserved_stock' => 'integer',
            'low_stock_threshold' => 'integer',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Color, $this> */
    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Stock disponivel para venda: fisico menos o reservado por pagamentos
     * Multibanco pendentes. SEMPRE calculado — nunca persistido (plano).
     *
     * @return Attribute<int, never>
     */
    protected function availableStock(): Attribute
    {
        return Attribute::get(fn (): int => $this->stock - $this->reserved_stock);
    }

    public function isLowStock(): bool
    {
        return $this->available_stock <= $this->low_stock_threshold;
    }
}
