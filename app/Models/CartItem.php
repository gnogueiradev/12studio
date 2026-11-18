<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $variant_id
 * @property int $qty
 * @property array<string, string>|null $personalization
 * @property string $personalization_hash
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Cart $cart
 * @property-read Variant $variant
 */
#[Fillable(['variant_id', 'qty', 'personalization', 'personalization_hash'])]
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    /**
     * A chave da linha: as mesmas escolhas, por qualquer ordem, dao o mesmo md5.
     *
     * @param  array<string, string>  $personalization  ja limpa
     */
    public static function hashFor(array $personalization): string
    {
        ksort($personalization);

        return md5((string) json_encode($personalization, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'personalization' => 'array',
        ];
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
