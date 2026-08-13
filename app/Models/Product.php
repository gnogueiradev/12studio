<?php

namespace App\Models;

use App\Concerns\HasTags;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $category_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $status
 * @property bool $featured
 * @property int $vat_rate
 * @property string $fulfillment_mode
 * @property int|null $production_time_days
 * @property bool $allow_backorder
 * @property int|null $max_open_production_qty
 * @property array<int, array<string, mixed>>|null $personalization_fields
 * @property int|null $personalization_surcharge_cents
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category|null $category
 *
 * Agregados da listagem do backoffice. Nao sao colunas: so existem quando a
 * consulta os pede com withSum(), e vem a null num produto sem variantes.
 * @property-read int|null $ready_stock
 */
#[Fillable([
    'category_id', 'name', 'slug', 'description', 'status', 'featured',
    'vat_rate', 'fulfillment_mode', 'production_time_days', 'allow_backorder',
    'max_open_production_qty', 'personalization_fields',
    'personalization_surcharge_cents', 'seo_title', 'seo_description',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasTags;

    // Convencao qrcode: const arrays em vez de PHP enums.
    public const STATUSES = ['draft', 'active', 'archived'];

    public const FULFILLMENT_MODES = ['in_stock', 'made_to_order', 'custom'];

    public function tagScope(): string
    {
        return Tag::SCOPE_PRODUCT;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'vat_rate' => 'integer',
            'production_time_days' => 'integer',
            'allow_backorder' => 'boolean',
            'max_open_production_qty' => 'integer',
            'personalization_fields' => 'array',
            'personalization_surcharge_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<Variant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /** @return HasOne<ProductImage, $this> */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    /** @return HasOne<Variant, $this> */
    public function defaultVariant(): HasOne
    {
        return $this->hasOne(Variant::class)->where('is_default', true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isMadeToOrder(): bool
    {
        return $this->fulfillment_mode === 'made_to_order';
    }

    /**
     * Personalizados sao isentos do direito de resolucao de 14 dias — a
     * pagina do produto mostra a nota de devolucao quando isto e true.
     */
    public function isCustom(): bool
    {
        return $this->fulfillment_mode === 'custom';
    }

    public function isPersonalizable(): bool
    {
        return ! empty($this->personalization_fields);
    }
}
