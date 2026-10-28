<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma categoria noutro idioma. Escreve-se pelo TranslationService.
 *
 * @property int $id
 * @property int $category_id
 * @property string $locale
 * @property string|null $name
 * @property string|null $description
 * @property string|null $source_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['category_id', 'locale', 'name', 'description', 'source_hash'])]
class CategoryTranslation extends Model
{
    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
