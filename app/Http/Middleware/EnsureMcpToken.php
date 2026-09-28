<?php

namespace App\Http\Middleware;

use App\Mcp\CurrentToken;
use App\Mcp\McpAuditor;
use App\Models\McpActivity;
use App\Models\User;
use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Porteiro do /mcp, depois do `auth:api` e do `admin`:
 *
 *   - o MCP pode estar desligado (MCP_ENABLED=false) — 503 para todos;
 *   - em producao so HTTPS (o token nunca viaja em claro);
 *   - o token tem de trazer o scope mcp:read;
 *   - a validade escolhida na criacao da chave (expires_at da linha) conta,
 *     mesmo que o JWT ainda dure mais.
 *
 * As recusas ficam no rasto do MCP: e ai que se ve alguem a tentar uma chave
 * velha ou revogada.
 */
class EnsureMcpToken
{
    public function __construct(
        private McpAuditor $auditor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mcp.enabled')) {
            abort(503, 'O MCP está desligado.');
        }

        if (app()->isProduction() && ! $request->isSecure()) {
            abort(403, 'Só por HTTPS.');
        }

        $user = $request->user();
        $token = $user instanceof User ? CurrentToken::model($user) : null;

        if (! $user instanceof User || $token === null || ! $user->tokenCan(ApiKeyService::SCOPE_READ)) {
            $this->auditor->record($user instanceof User ? $user : null, '(ligação)', McpActivity::RESULT_DENIED);

            abort(403, 'Este token não dá acesso ao MCP.');
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            $this->auditor->record($user, '(ligação)', McpActivity::RESULT_DENIED);

            abort(401, 'Esta chave expirou.');
        }

        return $next($request);
    }
}
