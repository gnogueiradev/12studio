<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma imagem de um anuncio da Etsy, e a fotografia do produto de onde veio
 * (quando veio de ca).
 *
 * @property int $id
 * @property int $listing_id
 * @property int|null $product_image_id
 * @property int|null $etsy_image_id
 * @property int|null $rank
 * @property string|null $source_path
 * @property bool $excluded
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['listing_id', 'product_image_id', 'etsy_image_id', 'rank', 'source_path', 'excluded'])]
class EtsyListingImage extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_image_id' => 'integer',
            'etsy_image_id' => 'integer',
            'rank' => 'integer',
            'excluded' => 'boolean',
        ];
    }

    /** @return BelongsTo<EtsyListing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(EtsyListing::class, 'listing_id');
    }

    /** @return BelongsTo<ProductImage, $this> */
    public function productImage(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class);
    }
}
