<?php

namespace App\Support;

use App\Services\ThemeSettings;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * O que o <head> do Blade muda nas paginas da montra: as letras do tema e o
 * favicon. No backoffice fica tudo como sempre (Instrument Sans e o favicon
 * da marca).
 */
final class StoreHead
{
    /**
     * As letras a carregar. Uma familia que nao esteja no build (a Bunny Fonts
     * em baixo na altura do deploy) nao pode deitar abaixo todas as paginas:
     * fica a letra do sistema e regista-se o erro.
     */
    public static function fonts(bool $storePage): HtmlString
    {
        $aliases = $storePage
            ? app(ThemeSettings::class)->fontAliases()
            : [ThemeSettings::DEFAULT_FONT];

        try {
            return app(Vite::class)->fonts(array_values($aliases));
        } catch (Throwable $exception) {
            report($exception);

            return new HtmlString('');
        }
    }

    public static function favicon(bool $storePage): ?string
    {
        return $storePage ? app(ThemeSettings::class)->faviconUrl() : null;
    }
}
