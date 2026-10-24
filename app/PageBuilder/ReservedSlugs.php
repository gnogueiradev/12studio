<?php

namespace App\PageBuilder;

use App\Models\Page;
use Illuminate\Support\Facades\Route;

/**
 * Os enderecos que uma landing page nao pode ter: o primeiro segmento de
 * qualquer rota registada (admin, login, produtos...), os codigos de idioma e
 * os caminhos das paginas de sistema. Calculado das rotas, e nao de uma lista
 * a mao, para uma rota nova ficar protegida sem ninguem se lembrar.
 */
class ReservedSlugs
{
    /** Por cima das rotas: o que o servidor serve fora do Laravel. */
    private const STATIC = ['build', 'storage', 'favicon.ico', 'robots.txt', 'sitemap.xml', 'up', 'index.php'];

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        $reserved = self::STATIC;

        foreach (Route::getRoutes() as $route) {
            $first = explode('/', trim($route->uri(), '/'))[0];

            if ($first !== '' && ! str_starts_with($first, '{')) {
                $reserved[] = $first;
            }
        }

        foreach (Page::SYSTEM as $path) {
            $first = explode('/', trim($path, '/'))[0];

            if ($first !== '' && ! str_starts_with($first, '{')) {
                $reserved[] = $first;
            }
        }

        $reserved = array_merge($reserved, array_keys((array) config('locales.available')), ['categoria', 'conversa']);

        return array_values(array_unique(array_map('strtolower', $reserved)));
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower($slug), self::all(), true);
    }
}
