<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLoginGate
{
    /**
     * Nome do cookie que marca este browser como autorizado.
     */
    public const COOKIE = 'studio_gate';

    /**
     * Esconde as paginas de autenticacao de quem nao conhece o URL secreto.
     *
     * Isto NAO e autenticacao — o Fortify e o EnsureAdmin continuam a ser a
     * seguranca a serio. Isto so evita que o formulario de login apareca a
     * quem passa pelo site enquanto a loja esta fechada.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('access.login_secret');

        // Sem segredo configurado o cadeado esta desligado (dev local e CI).
        if (! is_string($secret) || $secret === '') {
            return $next($request);
        }

        // Quem ja esta autenticado nunca fica preso fora: o cookie dura dias
        // mas a sessao dura horas, e sem esta clausula um admin com o cookie
        // expirado perdia o /logout e o confirm-password (rotas do Fortify).
        if ($request->user() !== null) {
            return $next($request);
        }

        $cookie = $request->cookie(self::COOKIE);

        if (is_string($cookie) && hash_equals(self::token($secret), $cookie)) {
            return $next($request);
        }

        // 404 e nao 403: um 403 confirmaria que a rota existe.
        abort(404);
    }

    /**
     * Valor guardado no cookie. E derivado do segredo, nunca o segredo em
     * bruto — assim rodar o LOGIN_GATE_SECRET invalida os cookies antigos.
     */
    public static function token(string $secret): string
    {
        return hash_hmac('sha256', $secret, (string) config('app.key'));
    }
}
