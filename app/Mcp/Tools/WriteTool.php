<?php

namespace App\Mcp\Tools;

use App\Mcp\CurrentToken;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;

/**
 * Base das ferramentas que alteram dados.
 *
 *   - so aparecem a tokens com o scope mcp:write (`shouldRegister`), e o
 *     scope volta a ser verificado em cada chamada (`deny`);
 *   - os argumentos ficam no rasto (com dados pessoais mascarados);
 *   - ha um limite proprio de escritas por minuto, mais apertado que o geral.
 */
abstract class WriteTool extends StudioTool
{
    /** Escritas por minuto, por token. */
    public const WRITES_PER_MINUTE = 20;

    protected bool $auditArguments = true;

    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->tokenCan(ApiKeyService::SCOPE_WRITE);
    }

    protected function deny(User $user): ?string
    {
        if (! $user->tokenCan(ApiKeyService::SCOPE_WRITE)) {
            return 'Esta chave é só de leitura.';
        }

        $key = 'mcp-write:'.(CurrentToken::id($user) ?? 'user-'.$user->getKey());

        if (RateLimiter::tooManyAttempts($key, self::WRITES_PER_MINUTE)) {
            return 'Demasiadas alterações seguidas. Espera um minuto.';
        }

        RateLimiter::hit($key, 60);

        return null;
    }
}
