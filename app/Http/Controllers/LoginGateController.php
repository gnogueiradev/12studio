<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureLoginGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LoginGateController extends Controller
{
    /**
     * Abre o cadeado: /acesso/<segredo> grava o cookie neste browser e manda
     * para o login. Segredo errado da o mesmo 404 de qualquer rota inexistente.
     */
    public function __invoke(Request $request, string $secret): RedirectResponse
    {
        $expected = config('access.login_secret');

        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $secret)) {
            abort(404);
        }

        $days = max(1, (int) config('access.login_secret_ttl_days', 30));

        return redirect()->route('login')->withCookie(cookie(
            name: EnsureLoginGate::COOKIE,
            value: EnsureLoginGate::token($expected),
            minutes: $days * 24 * 60,
            path: '/',
            domain: null,
            secure: $request->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }
}
