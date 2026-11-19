<?php

namespace App\Http\Middleware;

use App\Support\LocalizedUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A área do cliente (alias `customer.usable`), depois do `auth:customer`.
 *
 * A guarda `customer` partilha o provider `users` com a `web`: uma conta
 * desativada depois de entrar, ou promovida a equipa, continuava com a sessão
 * de cliente aberta. Aqui sai na página seguinte — só da `customer`, e sem
 * invalidate(): a sessão é a mesma do backoffice e do carrinho.
 */
class EnsureCustomerUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('customer')->user();

        if ($user !== null && ($user->isDisabled() || $user->isStaff())) {
            Auth::guard('customer')->logout();
            $request->session()->regenerateToken();

            return redirect(LocalizedUrl::path('/entrar'));
        }

        return $next($request);
    }
}
