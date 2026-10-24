<?php

namespace App\Models;

use App\Images\HasImageVariants;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Uma imagem da biblioteca dos blocos da montra.
 *
 * @property int $id
 * @property string $path
 * @property string|null $original_name
 * @property string|null $alt
 * @property string|null $mime
 * @property int|null $size
 * @property int|null $width
 * @property int|null $height
 * @property array{webp: array<int, string>, og: string}|null $variants
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['path', 'original_name', 'alt', 'mime', 'size', 'width', 'height', 'variants'])]
class Media extends Model implements HasImageVariants
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'variants' => 'array',
        ];
    }

    public function imageDisk(): string
    {
        return 'public';
    }

    public function imagePath(): string
    {
        return $this->path;
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    /**
     * A mesma forma do ProductImage::responsive(), para o <ResponsiveImage>.
     *
     * @return array{src: string, srcset: string|null, width: int|null, height: int|null, alt: string}
     */
    public function responsive(string $fallbackAlt = ''): array
    {
        $disk = Storage::disk('public');
        $webp = (array) ($this->variants['webp'] ?? []);

        return [
            'src' => $this->url(),
            'srcset' => $webp === [] ? null : collect($webp)
                ->map(fn (string $path, int|string $width): string => $disk->url($path)." {$width}w")
                ->implode(', '),
            'width' => $this->width,
            'height' => $this->height,
            'alt' => $this->alt ?: $fallbackAlt,
        ];
    }
}
