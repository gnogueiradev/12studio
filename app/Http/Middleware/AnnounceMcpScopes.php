<?php

namespace App\Http\Middleware;

use App\Http\Controllers\McpOAuthMetadataController;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O OAuthRegisterController do laravel/mcp responde ao registo com
 * `"scope": "mcp:use"`, o scope unico do pacote. Aqui troca-se pelos nossos,
 * para o cliente nao ficar a pedir um scope que so da leitura.
 */
class AnnounceMcpScopes
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $data = $response->getData(true);

            if (is_array($data)) {
                $data['scope'] = implode(' ', McpOAuthMetadataController::SCOPES);
                $response->setData($data);
            }
        }

        return $response;
    }
}
