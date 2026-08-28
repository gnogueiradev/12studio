<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limita o pedido e a confirmacao de reposicao de password.
 *
 * O Fortify limita o login, o segundo fator e as passkeys — o config/fortify.php
 * declara um limitador para cada — mas as duas rotas de reposicao ficam so com o
 * middleware `guest`, sem tecto nenhum. Sem isto, um POST em ciclo ao
 * /forgot-password enche a caixa de correio de quem tem conta e queima a quota
 * de envio do SMTP.
 *
 * Hoje o cadeado do EnsureLoginGate tapa-as (quem nao traz o cookie leva 404),
 * mas isso e ocultacao e nao limite: assenta em ninguem conhecer o URL secreto,
 * e cai por completo na Fase 5, quando a loja abrir contas de cliente e as
 * rotas de autenticacao deixarem de estar escondidas.
 *
 * Existe como middleware, e nao como entrada no config/fortify.php, porque o
 * Fortify nao le limitador nenhum para estas duas rotas — regista-as com
 * `guest` e mais nada (ver vendor/laravel/fortify/routes/routes.php).
 */
class ThrottlePasswordReset
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * Por NOME de rota e nao por caminho: o Fortify deixa reconfigurar os URIs
     * (RoutePath::for), e um dia em que /forgot-password passasse a
     * /recuperar-password o limite desaparecia em silencio.
     *
     * @var array<int, string>
     */
    private const LIMITED_ROUTES = ['password.email', 'password.update'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isPasswordReset($request)) {
            return $next($request);
        }

        $key = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new ThrottleRequestsException(
                'Demasiados pedidos de reposicao de password. Tenta daqui a pouco.',
                null,
                ['Retry-After' => RateLimiter::availableIn($key)],
            );
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return $next($request);
    }

    private function isPasswordReset(Request $request): bool
    {
        return $request->isMethod('POST')
            && in_array((string) $request->route()?->getName(), self::LIMITED_ROUTES, true);
    }

    /**
     * Email + IP, o mesmo formato do limitador `login` do
     * FortifyServiceProvider. So por IP, uma unica rede partilhada bloqueava
     * toda a gente; so por email, quem soubesse um endereco bloqueava o dono
     * dele de reaver a conta.
     *
     * O IP so distingue visitantes por causa do trustProxies() do
     * bootstrap/app.php — sem ele todos chegavam com o IP do proxy.
     */
    private function throttleKey(Request $request): string
    {
        $email = Str::lower((string) $request->input(Fortify::email()));

        return 'password-reset|'.Str::transliterate($email).'|'.$request->ip();
    }
}
