<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Uma ligacao de entrada enviada por email. Guarda o hash do segredo, nunca o
 * segredo. Usada ou expirada ha mais de um dia, sai: e um email, dado pessoal.
 *
 * @property int $id
 * @property string $email
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['email', 'token_hash', 'expires_at', 'used_at'])]
class CustomerLoginToken extends Model
{
    use Prunable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        $limit = now()->subDay();

        return static::query()
            ->where('used_at', '<', $limit)
            ->orWhere('expires_at', '<', $limit);
    }
}
