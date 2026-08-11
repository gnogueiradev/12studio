<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureLoginGate;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // O AddLinkHeadersForPreloadedAssets do starter kit NAO entra aqui: punha
        // num unico header `Link` os 26 assets que o Vite pre-carrega, 2 439
        // bytes so nesse header. No /login o bloco de headers chegava a 4 725
        // bytes e o nginx, que le a resposta do php-fpm num buffer de 4096
        // (fastcgi_buffer_size), deitava fora um 200 valido e devolvia 502
        // ("upstream sent too big header"). O preload nao se perde: as tags
        // <link rel="modulepreload"> continuam a ir no HTML — so deixa de haver
        // o adianto de as anunciar antes do parse.
        // Guardado por tests/Feature/ResponseHeaderSizeTest.php.
        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            // Aplicado a TODAS as rotas do Fortify via config/fortify.php.
            'login-gate' => EnsureLoginGate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
