<?php

namespace App\Services;

use App\Mail\ApiKeyCreatedMail;
use App\Models\McpActivity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Token;
use RuntimeException;

/**
 * Chaves de API (personal access tokens do Passport) para ligar o Claude ao
 * MCP. So admins; cada chave tem um nome, um acesso (leitura, ou leitura e
 * escrita) e uma validade curta e obrigatoria.
 *
 * O token em claro so existe no momento da criacao: o Passport guarda apenas
 * o id do JWT. Quem o perder cria outro.
 */
class ApiKeyService
{
    public const SCOPE_READ = 'mcp:read';

    public const SCOPE_WRITE = 'mcp:write';

    public const ACCESS_READ = 'read';

    public const ACCESS_WRITE = 'write';

    /** Validades que o formulario oferece, em dias. */
    public const LIFETIMES = [7, 30, 90];

    /**
     * @return array{token: string, key: Token}
     */
    public function create(User $user, string $name, string $access, int $days): array
    {
        $scopes = $access === self::ACCESS_WRITE
            ? [self::SCOPE_READ, self::SCOPE_WRITE]
            : [self::SCOPE_READ];

        $result = $user->createToken($name, $scopes);

        // O JWT leva a validade maxima global (90 dias). A escolhida aqui fica
        // no expires_at da linha, e o EnsureMcpToken recusa a chave passada
        // essa data — o Passport sozinho so olharia para o JWT.
        $key = $result->getToken();

        if ($key === null) {
            throw new RuntimeException('O Passport não devolveu a linha do token criado.');
        }

        $key->expires_at = CarbonImmutable::now()->addDays($days);
        $key->save();

        Mail::to($user)->send(new ApiKeyCreatedMail($user, $name, $access, $key->expires_at));

        return ['token' => $result->accessToken, 'key' => $key];
    }

    /**
     * Chaves pessoais e ligacoes OAuth ainda validas, com o ultimo uso tirado
     * do rasto do MCP (evita escrever na tabela de tokens a cada pedido).
     *
     * @return array<int, array{id: string, name: string, kind: string, access: string, createdAt: string|null, expiresAt: string|null, lastUsedAt: string|null}>
     */
    public function list(User $user): array
    {
        /** @var Collection<int, Token> $tokens */
        $tokens = $user->tokens()
            ->with('client')
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get();

        $lastUse = McpActivity::query()
            ->whereIn('token_id', $tokens->pluck('id'))
            ->selectRaw('token_id, max(created_at) as last_at')
            ->groupBy('token_id')
            ->pluck('last_at', 'token_id');

        return $tokens->map(fn (Token $token): array => [
            'id' => $token->id,
            'name' => $token->name ?? $token->client->name,
            'kind' => $token->client->hasGrantType('personal_access') ? 'key' : 'oauth',
            'access' => in_array(self::SCOPE_WRITE, $token->scopes, true) ? self::ACCESS_WRITE : self::ACCESS_READ,
            'createdAt' => $token->created_at?->format('Y-m-d H:i'),
            'expiresAt' => $token->expires_at?->format('Y-m-d'),
            'lastUsedAt' => isset($lastUse[$token->id])
                ? CarbonImmutable::parse((string) $lastUse[$token->id])->format('Y-m-d H:i')
                : null,
        ])->values()->all();
    }

    public function revoke(User $user, string $tokenId): bool
    {
        $token = $user->tokens()->whereKey($tokenId)->first();

        if ($token === null) {
            return false;
        }

        $this->revokeToken($token);

        return true;
    }

    /**
     * O "revogar tudo" da pagina, e o que corre sozinho quando a conta perde
     * o admin, muda de password, muda de papel ou e desativada.
     */
    public function revokeAll(User $user): int
    {
        $count = 0;

        $user->tokens()->where('revoked', false)->each(function (Token $token) use (&$count): void {
            $this->revokeToken($token);
            $count++;
        });

        return $count;
    }

    private function revokeToken(Token $token): void
    {
        $token->revoke();
        $token->refreshToken?->revoke();
    }
}
