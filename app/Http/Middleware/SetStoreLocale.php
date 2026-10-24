<?php

namespace App\Http\Middleware;

use App\Services\StoreSettings;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * O idioma das paginas da montra (alias `store.locale`).
 *
 * Vem do prefixo do URL ('/en/...') e nunca da sessao: cada idioma tem o seu
 * endereco, que e o que os motores de busca indexam e o que se partilha. Sem
 * prefixo e o de omissao. Um idioma que esta loja desligou da 404.
 *
 * Partilha com o frontend o idioma e os textos da montra. Corre como
 * middleware de rota — depois do HandleInertiaRequests, que vive no grupo
 * `web` e ainda nao sabe o idioma —, e so nas rotas da montra: o backoffice
 * nao leva estes textos.
 */
class SetStoreLocale
{
    public function __construct(
        private StoreSettings $store,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $default = (string) config('locales.default');
        $locale = $request->route('locale') ?? $default;

        if (! is_string($locale) || ! $this->store->hasLocale($locale)) {
            abort(404);
        }

        // Os controladores nao recebem o idioma como argumento: o mesmo
        // controlador serve '/produtos/x' e '/en/produtos/x'.
        $request->route()?->forgetParameter('locale');

        $this->apply($locale);

        return $next($request);
    }

    /**
     * Poe o idioma em vigor e partilha-o com o frontend, com os textos da
     * montra. Tambem usado pelas paginas de erro (StoreErrorPage), que nao
     * passam por este middleware.
     */
    public function apply(string $locale): void
    {
        app()->setLocale($locale);

        Inertia::share('locale', [
            'current' => $locale,
            'default' => (string) config('locales.default'),
            'available' => collect($this->store->locales())
                ->map(fn (string $code): array => [
                    'code' => $code,
                    'name' => (string) config("locales.available.$code.name"),
                    'intl' => (string) config("locales.available.$code.intl"),
                ])
                ->all(),
        ]);

        // Uma vez por idioma: o cliente guarda os textos e so os volta a pedir
        // quando muda de lingua (a chave muda com ela).
        Inertia::share('i18n', Inertia::once(fn (): array => (array) trans('store', [], $locale))
            ->as("i18n.$locale"));
    }
}
