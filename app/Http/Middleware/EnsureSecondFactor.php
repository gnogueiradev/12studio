<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorizar uma aplicacao (OAuth) da-lhe o backoffice sem passar pelo login.
 * Por isso so quem tem segundo fator — 2FA ou passkey — o pode fazer: a mesma
 * regra da pagina de chaves de API.
 */
class EnsureSecondFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        if ($user->hasEnabledTwoFactorAuthentication() || $user->passkeys()->exists()) {
            return $next($request);
        }

        Inertia::flash('toast', [
            'type' => 'error',
            'message' => 'Para ligar aplicações à tua conta, ativa primeiro o 2FA ou uma passkey.',
        ]);

        return redirect()->route('security.edit');
    }
}
