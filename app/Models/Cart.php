<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O carrinho de quem compra sem conta: vive num cookie com o token e na base
 * de dados com as linhas. Nao guarda precos — o CartQuoter le-os sempre.
 *
 * @property int $id
 * @property string $token
 * @property string|null $promo_code
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['promo_code'])]
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    use Prunable;

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    /**
     * Os carrinhos que ninguem mexe ha mais dias do que o cookie vive.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('updated_at', '<', now()->subDays((int) config('shop.cart.days')));
    }
}
