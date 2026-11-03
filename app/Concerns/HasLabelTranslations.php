<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Um rotulo curto traduzido num JSON da propria linha (`name_i18n`: cores e
 * materiais, que na montra so aparecem nos filtros e nas especificacoes).
 *
 * @mixin Model
 */
trait HasLabelTranslations
{
    public function translatedName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $labels = is_array($this->name_i18n) ? $this->name_i18n : [];
        $label = $labels[$locale] ?? null;

        return is_string($label) && trim($label) !== '' ? $label : (string) $this->name;
    }

    /**
     * So os idiomas conhecidos (fora o de omissao) e so os preenchidos.
     *
     * @param  array<mixed>|null  $labels
     * @return array<string, string>|null
     */
    public static function cleanLabels(?array $labels): ?array
    {
        $clean = [];

        foreach (array_keys((array) config('locales.available')) as $locale) {
            $value = $labels[$locale] ?? null;

            if ($locale !== config('locales.default') && is_scalar($value) && trim((string) $value) !== '') {
                $clean[$locale] = mb_substr(trim((string) $value), 0, 60);
            }
        }

        return $clean === [] ? null : $clean;
    }
}
