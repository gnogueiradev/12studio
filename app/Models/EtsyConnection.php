<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A loja da Etsy ligada ao 12studio (uma linha, no maximo).
 *
 * @property int $id
 * @property int $shop_id
 * @property string $shop_name
 * @property int $etsy_user_id
 * @property string $scopes
 * @property string $access_token
 * @property string $refresh_token
 * @property Carbon $access_expires_at
 * @property Carbon|null $orders_since
 * @property int|null $connected_by_user_id
 * @property Carbon|null $broken_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'shop_id', 'shop_name', 'etsy_user_id', 'scopes', 'access_token',
    'refresh_token', 'access_expires_at', 'orders_since',
    'connected_by_user_id', 'broken_at', 'last_error',
])]
#[Hidden(['access_token', 'refresh_token'])]
class EtsyConnection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shop_id' => 'integer',
            'etsy_user_id' => 'integer',
            // Cifrados com a APP_KEY: um backup da base de dados nao pode
            // trazer a loja la dentro.
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_expires_at' => 'datetime',
            'orders_since' => 'datetime',
            'broken_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function isBroken(): bool
    {
        return $this->broken_at !== null;
    }

    /**
     * @return array<int, string>
     */
    public function scopeList(): array
    {
        return array_values(array_filter(explode(' ', $this->scopes)));
    }

    /**
     * As permissoes que a integracao pede e a loja nao deu (ligada antes de
     * uma permissao nova ser acrescentada, ou recusada na Etsy).
     *
     * @return array<int, string>
     */
    public function missingScopes(): array
    {
        /** @var array<int, string> $required */
        $required = (array) config('etsy.scopes', []);

        return array_values(array_diff($required, $this->scopeList()));
    }
}
