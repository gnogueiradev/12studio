<?php

namespace App\Store;

use App\Services\BrandSettings;
use App\Services\StoreSettings;
use App\Support\LocalizedUrl;
use Illuminate\Support\Str;

/**
 * As meta tags de uma pagina da montra: description, canonical, hreflang,
 * Open Graph e JSON-LD. Vao para o Blade (withViewData) e nao para o React:
 * assim estao no HTML mesmo que o SSR caia, e testam-se com PHPUnit.
 */
final class Seo
{
    /**
     * @param  string  $path  O caminho no idioma por omissao ("/produtos/vaso").
     * @param  array<int, array<string, mixed>>  $jsonLd
     * @return array<string, mixed>
     */
    public static function make(
        string $path,
        string $title,
        ?string $description = null,
        ?string $image = null,
        string $type = 'website',
        array $jsonLd = [],
        bool $index = true,
    ): array {
        $locale = app()->getLocale();
        $default = (string) config('locales.default');
        $brand = app(BrandSettings::class)->name();

        $alternates = [];
        foreach (app(StoreSettings::class)->locales() as $code) {
            $alternates[] = [
                'hreflang' => (string) config("locales.available.$code.hreflang", $code),
                'href' => url(LocalizedUrl::path($path, $code)),
            ];
        }
        $alternates[] = ['hreflang' => 'x-default', 'href' => url(LocalizedUrl::path($path, $default))];

        return [
            'title' => $title === '' ? $brand : "{$title} - {$brand}",
            'description' => $description === null || trim($description) === ''
                ? null
                : Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags($description))), 160),
            'canonical' => url(LocalizedUrl::path($path, $locale)),
            'alternates' => $alternates,
            'image' => $image === null ? null : url($image),
            'type' => $type,
            'siteName' => $brand,
            'ogLocale' => str_replace('-', '_', (string) config("locales.available.$locale.intl", $locale)),
            'jsonLd' => $jsonLd,
            'robots' => $index ? null : 'noindex',
        ];
    }

    /**
     * A loja como organizacao (pagina inicial): nome, endereco e redes.
     *
     * @return array<string, mixed>
     */
    public static function organization(): array
    {
        $social = array_values(array_filter(app(StoreSettings::class)->toForm()['social']));
        $email = app(StoreSettings::class)->toForm()['contact_email'];

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => app(BrandSettings::class)->name(),
            'url' => url('/'),
            'email' => $email !== '' ? $email : null,
            'sameAs' => $social !== [] ? $social : null,
        ]);
    }

    /**
     * @param  array<int, array{label: string, href: string|null}>  $items
     * @return array<string, mixed>
     */
    public static function breadcrumb(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $item, int $index): array => array_filter([
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['label'],
                'item' => $item['href'] === null ? null : url($item['href']),
            ]), $items, array_keys($items)),
        ];
    }
}
