<?php

namespace App\Support;

/**
 * Caminhos da montra no idioma pedido: "/produtos" fica igual no idioma por
 * omissao e passa a "/en/produtos" nos outros. O gemeo do localizedUrl() de
 * resources/js/lib/i18n.ts, para o que o servidor resolve (menus, sitemap,
 * hreflang, links nos emails).
 */
class LocalizedUrl
{
    public static function path(string $path, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $path = '/'.ltrim($path, '/');

        if ($locale === config('locales.default')) {
            return $path;
        }

        return $path === '/' ? "/{$locale}" : "/{$locale}{$path}";
    }
}
