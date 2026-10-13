<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma variante do 12studio e o seu lugar no inventario de um anuncio da Etsy.
 *
 * @property int $id
 * @property int $variant_id
 * @property int $listing_id
 * @property int|null $etsy_product_id
 * @property int|null $etsy_offering_id
 * @property int|null $etsy_price_cents
 * @property bool $enabled
 * @property int|null $last_quantity_pushed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Variant $variant
 * @property-read EtsyListing $listing
 */
#[Fillable([
    'variant_id', 'listing_id', 'etsy_product_id', 'etsy_offering_id',
    'etsy_price_cents', 'enabled', 'last_quantity_pushed',
])]
class EtsyVariant extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'etsy_product_id' => 'integer',
            'etsy_offering_id' => 'integer',
            'etsy_price_cents' => 'integer',
            'enabled' => 'boolean',
            'last_quantity_pushed' => 'integer',
        ];
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /** @return BelongsTo<EtsyListing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(EtsyListing::class, 'listing_id');
    }
}
