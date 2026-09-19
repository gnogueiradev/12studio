<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corre em todas as rotas web, depois da sessao. Faz duas coisas:
 *
 *   1. Conta desativada => sai ja. O Fortify tambem recusa o login, mas uma
 *      sessao aberta antes de o dono desativar a conta, ou um login por
 *      passkey (que nao passa pelo authenticateUsing), so acabam aqui.
 *
 *   2. Password por mudar => so deixa ir a pagina de seguranca. O dono criou a
 *      conta com uma password que ele conhece; ate a pessoa escolher a sua,
 *      a conta nao e verdadeiramente dela.
 */
class EnsureAccountUsable
{
    /**
     * O minimo para conseguir mudar a password e sair: a pagina de seguranca
     * (e o confirm-password que ela pede), o PUT da password e o logout.
     *
     * @var array<int, string>
     */
    private const ALLOWED_WHILE_CHANGING_PASSWORD = [
        'security.edit',
        'user-password.update',
        'password.confirm',
        'password.confirmation',
        'password.confirm.store',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($user->isDisabled()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        if ($user->must_change_password && ! $request->routeIs(...self::ALLOWED_WHILE_CHANGING_PASSWORD)) {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => 'Antes de continuar, escolhe uma password tua.',
            ]);

            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}
