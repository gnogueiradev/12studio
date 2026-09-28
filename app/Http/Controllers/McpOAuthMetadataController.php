<?php

namespace App\Http\Controllers;

use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Os documentos de descoberta OAuth que o claude.ai le antes de pedir acesso.
 *
 * Existem aqui, e nao via Mcp::oauthRoutes(), por uma razao: o laravel/mcp
 * anuncia um scope unico, `mcp:use`, e o /mcp do 12studio distingue leitura
 * de escrita (mcp:read / mcp:write). Anunciar os nossos e o que faz o cliente
 * pedir os scopes certos — e o ecra de consentimento mostrar o que vai mesmo
 * ser dado.
 *
 * Os nomes das rotas sao os do pacote de proposito: o AddWwwAuthenticateHeader
 * do /mcp aponta para `mcp.oauth.protected-resource.nested` quando responde
 * 401, e e por ai que o cliente descobre isto tudo.
 */
class McpOAuthMetadataController extends Controller
{
    /** @var array<int, string> */
    public const SCOPES = [ApiKeyService::SCOPE_READ, ApiKeyService::SCOPE_WRITE];

    public function protectedResource(Request $request, ?string $path = null): JsonResponse
    {
        return response()->json([
            'resource' => url('/'.ltrim((string) $path, '/')),
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => self::SCOPES,
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function authorizationServer(Request $request, ?string $path = null): JsonResponse
    {
        return response()->json([
            'issuer' => $this->issuer(),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'registration_endpoint' => route('mcp.oauth.register'),
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => self::SCOPES,
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ]);
    }

    private function issuer(): string
    {
        $configured = config('mcp.authorization_server');

        return is_string($configured) && $configured !== '' ? $configured : url('/');
    }
}
