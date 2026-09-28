<?php

use App\Http\Controllers\McpOAuthMetadataController;
use App\Http\Middleware\AnnounceMcpScopes;
use App\Http\Middleware\NotifyOAuthApproval;
use App\Http\Middleware\NotifyOAuthRegistration;
use App\Mcp\Servers\StudioServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

// ── MCP do backoffice ────────────────────────────────────────────────────────
// Fora do grupo `web` (o McpServiceProvider carrega este ficheiro sem grupo):
// sem sessao, sem cookies, so Bearer token. Um browser com sessao de admin
// aberta nao consegue chamar isto — o CSRF nem se coloca.
//
// Ordem: teto por IP para quem nem token traz (throttle:120,1) ->
// autenticar (auth:api) -> conta admin, e ativa, a cada pedido (admin) ->
// interruptor, HTTPS, scope e validade da chave (mcp.token) -> limite por
// token (throttle:mcp).
Mcp::web('/mcp', StudioServer::class)
    ->middleware(['throttle:120,1', 'auth:api', 'admin', 'mcp.token', 'throttle:mcp'])
    ->name('mcp');

// ── OAuth para o claude.ai, o Desktop e o telemovel ─────────────────────────
// Tudo registado a mao em vez de Mcp::oauthRoutes() + rotas do Passport:
//   - a descoberta anuncia os NOSSOS scopes (mcp:read / mcp:write), e nao o
//     `mcp:use` unico do pacote;
//   - o registo dinamico tem limite, e so aceita redirects para claude.ai e
//     claude.com (config/mcp.php);
//   - do Passport so existem as quatro rotas do fluxo authorization code, e
//     o consentimento exige admin e password acabada de confirmar (2FA nao
//     e exigido — decisao do dono).
// Os nomes das rotas sao os que o laravel/mcp e o Passport esperam.

Route::get('/.well-known/oauth-protected-resource', [McpOAuthMetadataController::class, 'protectedResource'])
    ->name('mcp.oauth.protected-resource');
Route::get('/.well-known/oauth-protected-resource/{path}', [McpOAuthMetadataController::class, 'protectedResource'])
    ->where('path', '.*')
    ->name('mcp.oauth.protected-resource.nested');
Route::get('/.well-known/oauth-authorization-server', [McpOAuthMetadataController::class, 'authorizationServer'])
    ->name('mcp.oauth.authorization-server');
Route::get('/.well-known/oauth-authorization-server/{path}', [McpOAuthMetadataController::class, 'authorizationServer'])
    ->where('path', '.*')
    ->name('mcp.oauth.authorization-server.nested');

Route::post('/oauth/register', OAuthRegisterController::class)
    ->middleware(['throttle:mcp-register', AnnounceMcpScopes::class, NotifyOAuthRegistration::class])
    ->name('mcp.oauth.register');

Route::post('/oauth/token', [AccessTokenController::class, 'issueToken'])
    ->middleware('throttle:mcp-token')
    ->name('passport.token');

Route::middleware(['web', 'auth', 'admin', 'password.confirm'])
    ->prefix('oauth')
    ->name('passport.authorizations.')
    ->group(function (): void {
        Route::get('authorize', [AuthorizationController::class, 'authorize'])->name('authorize');
        Route::post('authorize', [ApproveAuthorizationController::class, 'approve'])
            ->middleware(NotifyOAuthApproval::class)
            ->name('approve');
        Route::delete('authorize', [DenyAuthorizationController::class, 'deny'])
            ->middleware(NotifyOAuthApproval::class)
            ->name('deny');
    });
