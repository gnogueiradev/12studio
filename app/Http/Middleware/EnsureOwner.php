<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwner
{
    /**
     * Gestao da equipa: so o dono. Um admin normal leva 403 — criar contas de
     * admin e dar a chave de casa, e isso fica com uma pessoa so.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isOwner()) {
            abort(403);
        }

        return $next($request);
    }
}
