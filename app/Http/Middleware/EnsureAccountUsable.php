<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corre em todas as rotas web, depois da sessao. Faz tres coisas:
 *
 *   1. Conta desativada => sai ja. O Fortify tambem recusa o login, mas uma
 *      sessao aberta antes de o dono desativar a conta, ou um login por
 *      passkey (que nao passa pelo authenticateUsing), so acabam aqui.
 *
 *   2. Cliente na guarda `web` => sai dela e segue como visitante. Pelas
 *      mesmas razoes: passkey e sessoes de antes da regra.
 *
 *   3. Password por mudar => so deixa ir a pagina de seguranca. O dono criou a
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

        // Quem nao e da equipa nao tem nada a fazer na guarda `web`: o
        // Fortify ja o recusa, mas um login por passkey ou uma sessao de antes
        // da regra so acabam aqui. Sai so da `web` — a sessao fica, com o
        // carrinho e a guarda `customer` — e o pedido segue como visitante:
        // a montra nunca manda um cliente para o login escondido da equipa.
        $webUser = Auth::guard('web')->user();

        if ($webUser !== null && ! $webUser->isStaff()) {
            Auth::guard('web')->logout();

            // O `auth` da rota ja correu (o Laravel ordena-o antes deste
            // middleware) e deixou-o passar. Numa rota que exige a `web`, o
            // que esse `auth` faria a um visitante: login.
            if ($this->requiresStaffSession($request)) {
                throw new AuthenticationException('Unauthenticated.', ['web'], route('login'));
            }

            return $next($request);
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

    /**
     * A rota pede `auth` (a guarda por omissao, que e a `web`) ou `auth:...`
     * com a `web` na lista — como as do Fortify, `auth:web`.
     */
    private function requiresStaffSession(Request $request): bool
    {
        foreach ($request->route()?->gatherMiddleware() ?? [] as $middleware) {
            if ($middleware === 'auth') {
                return true;
            }

            if (is_string($middleware) && str_starts_with($middleware, 'auth:')
                && in_array('web', explode(',', substr($middleware, strlen('auth:'))), true)) {
                return true;
            }
        }

        return false;
    }
}
