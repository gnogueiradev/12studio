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
        // Em producao a app corre ATRAS do Nginx Proxy Manager, que termina o
        // TLS e fala com o container em HTTP simples. Sem esta linha o Laravel
        // acredita no que ve — http, e o IP do proxy — e perde tres coisas de
        // uma so vez:
        //
        //   1. $request->isSecure() da false, e o cookie do cadeado sai SEM a
        //      flag Secure (LoginGateController passa-lhe isSecure()).
        //   2. $request->ip() devolve o IP do container do proxy a TODOS os
        //      visitantes. Os rate limiters do FortifyServiceProvider ficam com
        //      a componente de IP constante — o de passkeys, que e
        //      sessao|ip, degrada para "por sessao", e uma sessao roda-se.
        //   3. route()/url() geram http:// nos links dos emails de reset e de
        //      verificacao.
        //
        // `at: '*'` e seguro AQUI, e a razao e especifica deste deploy: o
        // servico `app` do docker-compose.yml nao publica portas nenhumas — so
        // e alcancavel pela rede do proxy, logo nao ha terceiro que possa
        // forjar um X-Forwarded-For. Fixar o IP do NPM nao e alternativa: o
        // container muda de IP a cada restart.
        // Guardado por tests/Feature/TrustedProxiesTest.php.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

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
