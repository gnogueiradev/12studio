<?php

namespace App\Mcp;

use App\Alerts\SecurityAlerts;
use App\Models\McpActivity;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Passport\Token;
use Throwable;

/**
 * Escreve o rasto do MCP (tabela mcp_activity).
 *
 * Nas escritas guarda os argumentos, com os dados pessoais mascarados e os
 * segredos cortados; nas leituras guarda so qual foi a ferramenta e como
 * correu — o que o Claude leu nao se duplica aqui.
 *
 * Nunca rebenta: uma falha a auditar nao pode estragar a resposta, e fica
 * so no log. E tambem daqui que o MCP chega ao #seguranca do Discord.
 */
class McpAuditor
{
    /** Chaves que nunca chegam a BD. */
    private const SECRET_KEYS = ['password', 'password_confirmation', 'token', 'secret', 'api_key'];

    /** Chaves com dados pessoais: ficam mascaradas. */
    private const PERSONAL_KEYS = ['email', 'phone', 'nif', 'line1', 'line2', 'postal_code', 'address'];

    /** Tamanho maximo de cada valor de texto guardado. */
    private const MAX_STRING = 300;

    /**
     * @param  array<string, mixed>|null  $arguments
     * @param  array<string, mixed>|null  $changes
     */
    public function record(
        ?User $user,
        string $tool,
        string $result,
        ?array $arguments = null,
        ?array $changes = null,
        ?int $durationMs = null,
    ): void {
        try {
            $request = request();
            $token = CurrentToken::model($user);

            $activity = McpActivity::query()->create([
                'user_id' => $user?->getKey(),
                'token_id' => $token?->getKey(),
                'client' => $this->clientLabel($token),
                'tool' => Str::limit($tool, 100, ''),
                'result' => $result,
                'arguments' => $arguments === null ? null : $this->sanitize($arguments),
                'changes' => $changes === null ? null : $this->sanitize($changes),
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
                'duration_ms' => $durationMs,
            ]);

            // Escritas, recusas e erros chegam ao #seguranca um a um; as
            // leituras vao no resumo de 15 minutos (alerts:mcp-reads).
            // Pelo container e nao pelo construtor: os testes unitarios
            // constroem o auditor com `new`, so para o sanitize().
            app(SecurityAlerts::class)->mcpActivity($activity);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function sanitize(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            $name = is_string($key) ? Str::lower($key) : '';

            if (in_array($name, self::SECRET_KEYS, true)) {
                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => $this->sanitize($value),
                in_array($name, self::PERSONAL_KEYS, true) => $this->mask($value),
                is_string($value) => Str::limit($value, self::MAX_STRING),
                default => $value,
            };
        }

        return $clean;
    }

    public function mask(mixed $value): ?string
    {
        return McpFormat::mask($value);
    }

    private function clientLabel(?Token $token): ?string
    {
        if ($token === null) {
            return null;
        }

        $label = collect([$token->name, $token->client?->name])->filter()->unique()->implode(' · ');

        return $label === '' ? null : Str::limit($label, 150, '');
    }
}
