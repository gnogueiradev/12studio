<?php

namespace App\Services;

use App\Models\Menu;
use App\Support\LocalizedUrl;

/**
 * Os menus da montra, ja resolvidos para o idioma da pagina.
 *
 * Um item guardado tem esta forma:
 *
 *   label    rotulo por idioma ({"pt": "Catalogo", "en": "Shop"}), ou
 *   label_key  uma chave do lang/{locale}/store.php (os menus de origem)
 *   type     path   caminho interno da montra ("/produtos") — leva o prefixo do idioma
 *            url    endereco externo (https://…)
 *            email  endereco de email (vira mailto:)
 *   target   o caminho, o URL ou o email
 *   children (so o rodape: os itens de cada coluna)
 *
 * O React recebe so { label, href, external, children }: nao precisa de
 * saber como se resolve um idioma nem de onde veio o rotulo.
 */
class StoreMenus
{
    /** O Livro de Reclamacoes Eletronico: obrigatorio para quem vende em Portugal. */
    public const COMPLAINTS_BOOK_URL = 'https://www.livroreclamacoes.pt/Inicio/';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(string $location): array
    {
        return match ($location) {
            'header' => [
                ['label_key' => 'nav.catalog', 'type' => 'path', 'target' => '/produtos'],
                ['label_key' => 'nav.custom', 'type' => 'path', 'target' => '/personalizado'],
                ['label_key' => 'nav.about', 'type' => 'path', 'target' => '/sobre'],
                ['label_key' => 'nav.contact', 'type' => 'path', 'target' => '/contacto'],
            ],
            'footer' => [
                ['label_key' => 'footer.help', 'type' => 'path', 'target' => '/contacto', 'children' => [
                    ['label_key' => 'footer.faq', 'type' => 'path', 'target' => '/faq'],
                    ['label_key' => 'nav.contact', 'type' => 'path', 'target' => '/contacto'],
                    ['label_key' => 'footer.shipping', 'type' => 'path', 'target' => '/envios-e-devolucoes'],
                    ['label_key' => 'nav.custom', 'type' => 'path', 'target' => '/personalizado'],
                ]],
                ['label_key' => 'footer.legal', 'type' => 'path', 'target' => '/termos', 'children' => [
                    ['label_key' => 'footer.terms', 'type' => 'path', 'target' => '/termos'],
                    ['label_key' => 'footer.privacy', 'type' => 'path', 'target' => '/privacidade'],
                    ['label_key' => 'footer.complaints_book', 'type' => 'url', 'target' => self::COMPLAINTS_BOOK_URL],
                ]],
            ],
            default => [],
        };
    }

    /**
     * @return array<int, array{label: string, href: string, external: bool, children: array<int, mixed>}>
     */
    public function resolved(string $location, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        $items = Menu::query()->where('location', $location)->value('items');
        $items = is_string($items) ? json_decode($items, true) : $items;

        if (! is_array($items)) {
            $items = self::defaults($location);
        }

        return $this->resolveItems($items, $locale);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{label: string, href: string, external: bool, children: array<int, mixed>}>
     */
    private function resolveItems(array $items, string $locale): array
    {
        $resolved = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $href = $this->href($item, $locale);
            $label = $this->label($item, $locale);

            // Um item partido (sem destino valido ou sem rotulo) sai do menu
            // em vez de aparecer como um link para lado nenhum.
            if ($href === null || $label === '') {
                continue;
            }

            $resolved[] = [
                'label' => $label,
                'href' => $href,
                'external' => ($item['type'] ?? null) === 'url',
                'children' => $this->resolveItems((array) ($item['children'] ?? []), $locale),
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function label(array $item, string $locale): string
    {
        if (is_string($item['label_key'] ?? null)) {
            return (string) trans('store.'.$item['label_key'], [], $locale);
        }

        $labels = is_array($item['label'] ?? null) ? $item['label'] : [];
        $default = (string) config('locales.default');

        return trim((string) ($labels[$locale] ?? $labels[$default] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function href(array $item, string $locale): ?string
    {
        $target = trim((string) ($item['target'] ?? ''));

        return match ($item['type'] ?? null) {
            // So caminhos da propria montra: "//outro.site" e
            // "/\outro.site" seriam lidos pelo browser como outro dominio.
            'path' => str_starts_with($target, '/') && ! preg_match('#^/[/\\\\]#', $target)
                ? LocalizedUrl::path($target, $locale)
                : null,
            'url' => preg_match('#^https://[^\s]+$#i', $target) ? $target : null,
            'email' => filter_var($target, FILTER_VALIDATE_EMAIL) ? "mailto:{$target}" : null,
            default => null,
        };
    }
}
