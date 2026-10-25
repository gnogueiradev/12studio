<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Campos de texto com traducao por idioma (produtos e categorias). O
 * portugues vive na propria tabela; os outros idiomas numa tabela
 * `*_translations`, uma linha por idioma.
 *
 * `translated('name')` devolve o texto no idioma pedido (o da pagina por
 * omissao) e cai no portugues, campo a campo, quando falta. Para nao fazer
 * uma query por produto, carregam-se as traducoes do idioma da pagina com
 * `->with(Product::withTranslations())`.
 *
 * @template TTranslation of Model
 *
 * @mixin Model
 */
trait HasTranslations
{
    /** Os campos que se traduzem. */
    abstract public static function translatableFields(): array;

    /** @return HasMany<TTranslation, $this> */
    abstract public function translations(): HasMany;

    /**
     * Para o eager loading: so as traducoes do idioma em vigor.
     *
     * @return array<string, \Closure>
     */
    public static function withTranslations(string $relation = 'translations'): array
    {
        $locale = app()->getLocale();

        return [$relation => fn ($query) => $query->where('locale', $locale)];
    }

    public function translated(string $field, ?string $locale = null): ?string
    {
        return $this->translation($field, $locale) ?? $this->getAttribute($field);
    }

    /**
     * So o texto desse idioma, sem cair no portugues (null se faltar). Para
     * campos que tem a sua propria alternativa — um titulo de SEO por
     * traduzir deve dar o nome traduzido, e nao o titulo portugues.
     */
    public function translation(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        if ($locale === config('locales.default')) {
            $original = $this->getAttribute($field);

            return is_string($original) && trim(strip_tags($original)) !== '' ? $original : null;
        }

        $translation = $this->relationLoaded('translations')
            ? $this->translations->firstWhere('locale', $locale)
            : $this->translations()->where('locale', $locale)->first();

        $value = $translation?->getAttribute($field);

        return is_string($value) && trim(strip_tags($value)) !== '' ? $value : null;
    }

    /**
     * O hash do texto portugues que se traduz: quando muda, as traducoes
     * feitas antes ficam desatualizadas.
     */
    public function sourceHash(): string
    {
        return hash('sha256', (string) json_encode(array_map(
            fn (string $field): string => trim((string) $this->getAttribute($field)),
            static::translatableFields(),
        )));
    }
}
