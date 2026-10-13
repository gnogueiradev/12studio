<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Um produto do 12studio e o anuncio dele na Etsy.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $etsy_listing_id
 * @property string|null $state
 * @property string|null $url
 * @property string|null $title
 * @property string|null $description
 * @property array<int, string>|null $tags
 * @property array<int, string>|null $materials
 * @property int|null $taxonomy_id
 * @property string|null $who_made
 * @property string|null $when_made
 * @property int|null $shipping_profile_id
 * @property int|null $return_policy_id
 * @property int|null $readiness_state_id
 * @property int|null $shop_section_id
 * @property array<array-key, mixed>|null $properties
 * @property string|null $video_path
 * @property int|null $etsy_video_id
 * @property array<string, int>|null $property_map
 * @property bool $sync_enabled
 * @property Carbon|null $auto_deactivated_at
 * @property Carbon|null $last_pushed_at
 * @property string|null $last_push_hash
 * @property Carbon|null $remote_checked_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 */
#[Fillable([
    'product_id', 'etsy_listing_id', 'state', 'url', 'title', 'description',
    'tags', 'materials', 'taxonomy_id', 'who_made', 'when_made',
    'shipping_profile_id', 'return_policy_id', 'readiness_state_id',
    'shop_section_id', 'properties', 'video_path', 'etsy_video_id',
    'property_map', 'sync_enabled', 'auto_deactivated_at', 'last_pushed_at',
    'last_push_hash', 'remote_checked_at', 'last_error',
])]
class EtsyListing extends Model
{
    // Os estados que a Etsy da a um anuncio, mais o nosso `deleted`.
    public const STATES = ['draft', 'active', 'inactive', 'sold_out', 'expired'];

    public const STATE_DELETED = 'deleted';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'etsy_listing_id' => 'integer',
            'tags' => 'array',
            'materials' => 'array',
            'taxonomy_id' => 'integer',
            'shipping_profile_id' => 'integer',
            'return_policy_id' => 'integer',
            'readiness_state_id' => 'integer',
            'shop_section_id' => 'integer',
            'properties' => 'array',
            'etsy_video_id' => 'integer',
            'property_map' => 'array',
            'sync_enabled' => 'boolean',
            'auto_deactivated_at' => 'datetime',
            'last_pushed_at' => 'datetime',
            'remote_checked_at' => 'datetime',
        ];
    }

    /**
     * Com os apagados, como Variant::product(): o anuncio de um produto
     * apagado ainda tem de saber de quem era para ser apagado la.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return HasMany<EtsyVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(EtsyVariant::class, 'listing_id');
    }

    /** @return HasMany<EtsyListingImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(EtsyListingImage::class, 'listing_id');
    }

    /**
     * Existe la? (Ligado a um anuncio que ainda nao foi apagado.)
     */
    public function isOnEtsy(): bool
    {
        return $this->etsy_listing_id !== null;
    }
}
