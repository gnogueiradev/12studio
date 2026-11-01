<?php

namespace App\Models;

use Database\Factories\PromoCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Um codigo de desconto. A fonte de verdade e o backoffice: o Stripe so ve um
 * cupao de uso unico com o valor ja calculado.
 *
 * @property int $id
 * @property string $code
 * @property string $type
 * @property int $value
 * @property int $min_subtotal_cents
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 * @property int|null $max_uses
 * @property int $uses_count
 * @property bool $single_use_per_email
 * @property string $channel
 * @property string|null $email
 * @property bool $is_active
 * @property Carbon|null $notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'code', 'type', 'value', 'min_subtotal_cents', 'starts_at', 'expires_at',
    'max_uses', 'single_use_per_email', 'channel', 'email', 'is_active',
])]
class PromoCode extends Model
{
    /** @use HasFactory<PromoCodeFactory> */
    use HasFactory;

    public const TYPE_FIXED = 'fixed';

    public const TYPE_PERCENT = 'percent';

    public const TYPES = [self::TYPE_FIXED, self::TYPE_PERCENT];

    public const CHANNEL_WELCOME = 'welcome';

    // O que se pode escolher a mao. Os de boas-vindas nascem sozinhos.
    public const MANUAL_CHANNELS = ['instagram', 'vinted', 'referral', 'store', 'other'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'min_subtotal_cents' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'max_uses' => 'integer',
            'uses_count' => 'integer',
            'single_use_per_email' => 'boolean',
            'is_active' => 'boolean',
            'notified_at' => 'datetime',
        ];
    }

    /** @return HasMany<PromoCodeRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    /**
     * O desconto sobre um subtotal: o fixo nunca passa do subtotal e o
     * percentual arredonda ao centimo, sem floats.
     */
    public function discountFor(int $subtotalCents): int
    {
        if ($this->type === self::TYPE_PERCENT) {
            return intdiv($subtotalCents * $this->value + 50, 100);
        }

        return min($this->value, $subtotalCents);
    }

    /**
     * A forma unica de comparar emails entre codigos e encomendas.
     */
    public static function normalizeEmail(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    public static function normalizeCode(string $code): string
    {
        return Str::upper(trim($code));
    }
}
