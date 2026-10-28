<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma publicacao de uma pagina, para o "Repor esta versao".
 *
 * @property int $id
 * @property int $page_id
 * @property array<int, array<string, mixed>> $blocks
 * @property array<string, mixed>|null $seo
 * @property int|null $user_id
 * @property Carbon|null $created_at
 */
#[Fillable(['page_id', 'blocks', 'seo', 'user_id', 'created_at'])]
class PageRevision extends Model
{
    public const UPDATED_AT = null;

    /** Quantas publicacoes se guardam por pagina. */
    public const KEEP = 20;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'seo' => 'array',
        ];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
