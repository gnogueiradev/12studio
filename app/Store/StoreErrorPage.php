<?php

namespace App\Store;

use App\Http\Middleware\SetStoreLocale;
use App\Http\Middleware\ShareStorefront;
use App\Services\StoreSettings;
use Inertia\ExceptionResponse;

/**
 * Os erros da montra (404, 500, 503) com o layout dela — header, pesquisa,
 * rodape — em vez da pagina crua do Laravel. So para pedidos de paginas da
 * montra com a loja aberta; o backoffice, as APIs e o modo de debug ficam
 * como estavam.
 */
class StoreErrorPage
{
    public const STATUSES = [404, 500, 503];

    /** Caminhos que nunca sao da montra. */
    private const OUTSIDE = [
        'admin', 'admin/*', 'dashboard', 'settings', 'settings/*', 'mcp', 'mcp/*', 'oauth/*',
        '.well-known/*', 'api/*', 'webhooks/*', 'login', 'logout', 'register', 'acesso/*',
        'two-factor-challenge', 'forgot-password', 'reset-password/*', 'email/*', 'user/*',
    ];

    public function __construct(
        private StoreSettings $store,
    ) {}

    public function __invoke(ExceptionResponse $response): ?ExceptionResponse
    {
        $request = $response->request;
        $status = $response->statusCode();

        if (! in_array($status, self::STATUSES, true)
            || $request->expectsJson()
            || $request->is(...self::OUTSIDE)
            || ($status >= 500 && config('app.debug'))
            || ! $this->store->isOpen()) {
            return null;
        }

        $first = explode('/', trim($request->path(), '/'))[0];
        $locale = $first !== config('locales.default') && $this->store->hasLocale($first)
            ? $first
            : (string) config('locales.default');

        app(SetStoreLocale::class)->apply($locale);
        app(ShareStorefront::class)->share();

        return $response->render('store/error', ['status' => $status])->withSharedData();
    }
}
