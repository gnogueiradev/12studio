<?php

namespace App\Http\Middleware;

use App\Alerts\SecurityAlerts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Avisa o #seguranca de cada cliente OAuth que se regista. O registo e
 * publico (e assim que o claude.ai se apresenta), por isso um registo que
 * ninguem pediu e o primeiro sinal de alguem a experimentar.
 */
class NotifyOAuthRegistration
{
    public function __construct(
        private SecurityAlerts $alerts,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Do pedido e nao da resposta: a resposta nao repete o nome. So se
        // chega aqui com sucesso, por isso o que foi pedido foi aceite.
        if ($response->isSuccessful()) {
            $name = $request->input('client_name');
            $redirects = $request->input('redirect_uris', []);

            $this->alerts->oauthClientRegistered(
                is_string($name) ? $name : null,
                is_array($redirects) ? array_values(array_filter($redirects, 'is_string')) : [],
            );
        }

        return $response;
    }
}
