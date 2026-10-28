<?php

namespace App\Models;

use App\Images\HasImageVariants;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int|null $color_id
 * @property string $path
 * @property int|null $width
 * @property int|null $height
 * @property array{webp: array<int, string>, og: string}|null $variants
 * @property string|null $alt
 * @property int $sort_order
 * @property bool $is_primary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $url
 * @property-read string $og_url
 */
#[Fillable(['product_id', 'variant_id', 'color_id', 'path', 'alt', 'sort_order', 'is_primary'])]
class ProductImage extends Model implements HasImageVariants
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_primary' => 'boolean',
            'width' => 'integer',
            'height' => 'integer',
            'variants' => 'array',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /** @return BelongsTo<Color, $this> */
    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    /** @return Attribute<string, never> */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => Storage::disk('public')->url($this->path));
    }

    /**
     * O JPEG de 1200 px para o Open Graph; o original enquanto nao houver.
     *
     * @return Attribute<string, never>
     */
    protected function ogUrl(): Attribute
    {
        return Attribute::get(fn (): string => is_string($this->variants['og'] ?? null)
            ? Storage::disk('public')->url($this->variants['og'])
            : $this->url);
    }

    public function imageDisk(): string
    {
        return 'public';
    }

    public function imagePath(): string
    {
        return $this->path;
    }

    /**
     * O que o <ResponsiveImage> da montra precisa: o original como `src` e,
     * quando ja houver versoes reduzidas, o `srcset` em WebP e as medidas
     * (que reservam o espaco e evitam o salto do layout).
     *
     * @return array{src: string, srcset: string|null, width: int|null, height: int|null, alt: string}
     */
    public function responsive(string $fallbackAlt = ''): array
    {
        $disk = Storage::disk('public');
        $webp = (array) ($this->variants['webp'] ?? []);

        $srcset = $webp === [] ? null : collect($webp)
            ->map(fn (string $path, int|string $width): string => $disk->url($path)." {$width}w")
            ->implode(', ');

        return [
            'src' => $this->url,
            'srcset' => $srcset,
            'width' => $this->width,
            'height' => $this->height,
            'alt' => $this->alt ?: $fallbackAlt,
        ];
    }
}
