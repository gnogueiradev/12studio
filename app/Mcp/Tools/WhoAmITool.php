<?php

namespace App\Mcp\Tools;

use App\Mcp\CurrentToken;
use App\Models\User;
use App\Services\ApiKeyService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('whoami')]
#[Title('Quem sou eu')]
#[Description('Mostra com que conta e com que acesso (só leitura, ou leitura e escrita) esta ligação ao 12studio está a funcionar, e até quando a chave é válida.')]
#[IsReadOnly]
#[IsIdempotent]
class WhoAmITool extends StudioTool
{
    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $token = CurrentToken::model($user);
        $canWrite = $user->tokenCan(ApiKeyService::SCOPE_WRITE);

        return Response::structured([
            'account' => $user->name,
            'role' => $user->role(),
            'access' => $canWrite ? 'leitura e escrita' : 'só leitura',
            'key' => $token?->name,
            'expires_at' => $token?->expires_at?->format('Y-m-d H:i'),
        ]);
    }
}
