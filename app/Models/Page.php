<?php

namespace App\Models;

use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Uma pagina da montra montada por blocos (ver App\PageBuilder).
 *
 * @property int $id
 * @property string $type
 * @property string|null $key
 * @property string|null $slug
 * @property string $name
 * @property string $status
 * @property array<string, array{title?: string, description?: string}>|null $seo
 * @property array<int, array<string, mixed>> $draft_blocks
 * @property array<int, array<string, mixed>>|null $published_blocks
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['type', 'key', 'slug', 'name', 'status', 'seo', 'draft_blocks', 'published_blocks', 'starts_at', 'ends_at', 'published_at'])]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    public const TYPES = ['system', 'landing'];

    /** Rascunho: nunca foi publicada. Despublicada: foi, e saiu do ar. */
    public const STATUSES = ['draft', 'published', 'unpublished'];

    /**
     * As paginas de sistema e o caminho de cada uma (no idioma por omissao).
     * O catalogo e o produto sao templates: o caminho e o da listagem, e
     * cada produto usa o mesmo layout.
     */
    public const SYSTEM = [
        'home' => '/',
        'catalog' => '/produtos',
        'product' => '/produtos/{slug}',
        'about' => '/sobre',
        'custom' => '/personalizado',
        'contact' => '/contacto',
        'faq' => '/faq',
        'terms' => '/termos',
        'privacy' => '/privacidade',
        'shipping' => '/envios-e-devolucoes',
    ];

    /** O "template" de blocos de cada pagina: que blocos pode ter. */
    public const TEMPLATES = [
        'catalog' => 'catalog',
        'product' => 'product',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seo' => 'array',
            'draft_blocks' => 'array',
            'published_blocks' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** @return HasMany<PageRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class)->latest('id');
    }

    public function template(): string
    {
        return self::TEMPLATES[$this->key ?? ''] ?? 'page';
    }

    public function isLanding(): bool
    {
        return $this->type === 'landing';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->published_blocks !== null;
    }

    /** Publicada e, numa landing com datas, dentro delas. */
    public function isLive(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $this->isPublished()
            && ($this->starts_at === null || $this->starts_at->lte($now))
            && ($this->ends_at === null || $this->ends_at->gt($now));
    }

    /** O rascunho difere do que esta publicado. */
    public function hasUnpublishedChanges(): bool
    {
        return $this->published_blocks !== $this->draft_blocks;
    }

    /** O caminho publico (idioma por omissao), ou null num template. */
    public function path(): ?string
    {
        if ($this->isLanding()) {
            return '/'.$this->slug;
        }

        $path = self::SYSTEM[$this->key ?? ''] ?? null;

        // O template do produto nao tem um caminho seu: serve todos os
        // /produtos/{slug}.
        return $path !== null && ! str_contains($path, '{') ? $path : null;
    }
}
