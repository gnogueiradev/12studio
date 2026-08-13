<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $scope
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * Agregados da listagem de gestao. Nao sao colunas: so existem quando a
 * consulta os pede com withCount().
 * @property-read int|null $products_count
 * @property-read int|null $customers_count
 * @property-read int|null $orders_count
 */
#[Fillable(['scope', 'name', 'slug'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    public const SCOPE_PRODUCT = 'product';

    public const SCOPE_CUSTOMER = 'customer';

    public const SCOPE_ORDER = 'order';

    // Convencao qrcode: const arrays em vez de PHP enums.
    public const SCOPES = [self::SCOPE_PRODUCT, self::SCOPE_CUSTOMER, self::SCOPE_ORDER];

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    /**
     * Cliente e um User com is_admin = false. O filtro esta aqui e nao so em
     * quem pergunta, para a contagem de usos da pagina de gestao nunca incluir
     * uma conta de administrador.
     *
     * @return BelongsToMany<User, $this>
     */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->where('is_admin', false);
    }

    /** @return BelongsToMany<Order, $this> */
    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeInScope(Builder $query, string $scope): Builder
    {
        return $query->where('scope', $scope);
    }
}
