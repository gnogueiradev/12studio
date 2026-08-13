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
 * @property int|null $wholesale_price_cents
 * @property int $stock
 * @property int $reserved_stock
 * @property int $low_stock_threshold
 * @property bool $is_default
 * @property bool $active
 * @property int|null $filament_weight_grams
 * @property int|null $printing_time_minutes
 * @property int|null $printer_profile_id
 * @property int|null $extra_cost_cents
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
 * @property-read PrinterProfile|null $printerProfile
 */
#[Fillable([
    'product_id', 'sku', 'color_id', 'size_label', 'price_cents',
    'compare_at_cents', 'wholesale_price_cents', 'stock', 'reserved_stock',
    'low_stock_threshold',
    'is_default', 'active', 'filament_weight_grams', 'printing_time_minutes',
    'printer_profile_id', 'extra_cost_cents',
    'product_weight_grams', 'package_weight_grams',
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
            'wholesale_price_cents' => 'integer',
            'stock' => 'integer',
            'reserved_stock' => 'integer',
            'low_stock_threshold' => 'integer',
            'is_default' => 'boolean',
            'active' => 'boolean',
            'color_id' => 'integer',
            'filament_weight_grams' => 'integer',
            // Entram todos na calculadora de precos: sem cast, um "90" vindo
            // do SQLite chegava ao PricingInput como string.
            'printing_time_minutes' => 'integer',
            'printer_profile_id' => 'integer',
            'extra_cost_cents' => 'integer',
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

    /**
     * A impressora em que esta variante e feita. Null = a predefinida —
     * o custo/hora quase nunca difere e obrigar a escolher em cada variante
     * era ceremonia sem ganho.
     *
     * @return BelongsTo<PrinterProfile, $this>
     */
    public function printerProfile(): BelongsTo
    {
        return $this->belongsTo(PrinterProfile::class);
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

    /**
     * A BD guarda `price_cents` = preco EFETIVO (o que o cliente paga, do
     * qual dependem carrinho, order_items e Stripe) e `compare_at_cents` =
     * preco riscado. O admin pensa ao contrario — "preco normal" e "preco
     * promocional" — por isso o formulario le por estes dois metodos, e a
     * traducao vive so aqui e no VariantService.
     *
     * Metodos e nao acessores, como Color::effectivePricePerKgCents(): sao
     * derivados, nao atributos, e nao tem de aparecer no toArray().
     */
    public function normalPriceCents(): int
    {
        return $this->compare_at_cents ?? $this->price_cents;
    }

    public function salePriceCents(): ?int
    {
        return $this->compare_at_cents === null ? null : $this->price_cents;
    }

    public function isOnSale(): bool
    {
        return $this->compare_at_cents !== null;
    }
}
