<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $variant_id
 * @property string $product_name
 * @property string|null $variant_label
 * @property string|null $sku
 * @property int $unit_price_cents
 * @property int|null $catalog_unit_price_cents
 * @property string|null $price_override_reason
 * @property int $personalization_surcharge_cents
 * @property int $qty
 * @property int $line_total_cents
 * @property int $vat_rate
 * @property string|null $image_url
 * @property array<string, mixed>|null $personalization
 * @property string $fulfillment_mode
 * @property string $production_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 */
#[Fillable([
    'order_id', 'variant_id', 'product_name', 'variant_label', 'sku',
    'unit_price_cents', 'catalog_unit_price_cents', 'price_override_reason',
    'personalization_surcharge_cents', 'qty', 'line_total_cents', 'vat_rate',
    'image_url', 'personalization', 'fulfillment_mode', 'production_status',
])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    // Producao por item: not_required para pecas em stock; o resto e o
    // percurso da impressora. A encomenda auto-avanca para ready_to_ship
    // quando todos os itens ficam ready/not_required (OrderService).
    public const PRODUCTION_STATUSES = [
        'not_required', 'awaiting_production', 'printing', 'quality_check', 'ready',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'catalog_unit_price_cents' => 'integer',
            'personalization_surcharge_cents' => 'integer',
            'qty' => 'integer',
            'line_total_cents' => 'integer',
            'vat_rate' => 'integer',
            'personalization' => 'array',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /** @return HasMany<OrderItemStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderItemStatusHistory::class);
    }

    public function requiresProduction(): bool
    {
        return $this->production_status !== 'not_required';
    }

    public function isReady(): bool
    {
        return in_array($this->production_status, ['not_required', 'ready'], true);
    }
}
