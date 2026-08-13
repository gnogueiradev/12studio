<?php

namespace App\Concerns;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Etiquetas em qualquer entidade. O pivot nao se declara: os tres nomes que o
 * Eloquent deriva por ordem alfabetica sao exatamente os que existem —
 * `product_tag`, `tag_user` e `order_tag`.
 *
 * @phpstan-require-extends Model
 */
trait HasTags
{
    /**
     * Em que ambito do vocabulario vive esta entidade. Uma das Tag::SCOPES.
     */
    abstract public function tagScope(): string;

    /**
     * O filtro por `scope` na propria relacao, e nao so no servico que escreve:
     * assim uma etiqueta do ambito errado que tivesse ido parar ao pivot nunca
     * chega a ser lida, contada nem filtrada.
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->where('tags.scope', $this->tagScope())
            ->orderBy('name');
    }
}
