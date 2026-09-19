<?php

use App\Mcp\Servers\StudioServer;
use Laravel\Mcp\Facades\Mcp;

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
