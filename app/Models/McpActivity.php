<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma chamada a uma ferramenta do MCP. Escrita pelo McpAuditor, lida na
 * pagina "Atividade MCP". Nunca editada.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $token_id
 * @property string|null $client
 * @property string $tool
 * @property string $result
 * @property array<string, mixed>|null $arguments
 * @property array<string, mixed>|null $changes
 * @property string|null $ip
 * @property string|null $user_agent
 * @property int|null $duration_ms
 * @property CarbonImmutable|null $created_at
 */
#[Fillable([
    'user_id', 'token_id', 'client', 'tool', 'result', 'arguments', 'changes',
    'ip', 'user_agent', 'duration_ms',
])]
class McpActivity extends Model
{
    use Prunable;

    public const RESULT_OK = 'ok';

    public const RESULT_VALIDATION_ERROR = 'validation_error';

    public const RESULT_ERROR = 'error';

    public const RESULT_DENIED = 'denied';

    /** Meses que o rasto fica guardado. */
    public const RETENTION_MONTHS = 12;

    protected $table = 'mcp_activity';

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths(self::RETENTION_MONTHS));
    }
}
