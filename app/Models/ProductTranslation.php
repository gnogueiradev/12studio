<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Um produto noutro idioma. Escreve-se pelo TranslationService.
 *
 * @property int $id
 * @property int $product_id
 * @property string $locale
 * @property string|null $name
 * @property string|null $description
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property string|null $search_text
 * @property string|null $source_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['product_id', 'locale', 'name', 'description', 'seo_title', 'seo_description', 'search_text', 'source_hash'])]
class ProductTranslation extends Model
{
    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
