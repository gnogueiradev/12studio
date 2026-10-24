<?php

namespace App\Http\Middleware;

use App\Services\StoreSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * O porteiro da montra publica (alias `store.open`).
 *
 * Fechada: '/' devolve a landing "em breve" e as outras paginas da montra
 * voltam para '/'. O admin passa na mesma — e assim que prepara o lancamento —
 * e a montra leva `storePreview` para o StoreLayout mostrar que so ele a ve.
 */
class EnsureStoreOpen
{
    public function __construct(
        private StoreSettings $store,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->store->isOpen()) {
            Inertia::share('storePreview', false);

            return $next($request);
        }

        if ($request->user()?->isAdmin()) {
            Inertia::share('storePreview', true);

            return $next($request);
        }

        if (! $request->routeIs('home', 'i18n.home')) {
            return redirect()->route('home');
        }

        // Pelo Router::toResponse e nao so ->toResponse(): e ele que prepara a
        // resposta (Content-Type incluido) como faria a um controlador, e sem
        // isso o SecurityHeaders nao a reconhecia como HTML e saltava o CSP.
        return Router::toResponse($request, Inertia::render('coming-soon'));
    }
}
