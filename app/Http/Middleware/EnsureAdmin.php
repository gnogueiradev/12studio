<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    /**
     * Bloqueia tudo o que nao seja um admin autenticado. Terceira camada de
     * defesa nao — a primeira: layouts e controllers tambem verificam, porque
     * middleware sozinho nao e seguranca (padrao qrcode).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isAdmin()) {
            abort(403);
        }

        return $next($request);
    }
}
