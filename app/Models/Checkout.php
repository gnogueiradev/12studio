<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CheckoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma compra da loja congelada no "Pagar": as linhas com os precos desse
 * momento, quem compra, para onde vai e o total. E nisto que o webhook confia
 * para criar a encomenda — o carrinho pode mudar depois do redirect para o
 * Stripe.
 *
 * Guarda dados pessoais de quem pode nunca chegar a comprar: sem encomenda,
 * sai ao fim de 30 dias (prunable). Com encomenda fica, que e o rasto dela,
 * mas sem os dados pessoais, que passaram para a encomenda.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $token
 * @property int|null $cart_id
 * @property int|null $order_id
 * @property array<int, array<string, mixed>> $items_snapshot
 * @property array{name: string, email: string, phone: string, nif: string|null}|null $customer
 * @property array{name: string, line1: string, line2: string|null, postalCode: string, city: string, country: string, phone: string}|null $shipping_address
 * @property int $subtotal_cents
 * @property int $shipping_cents
 * @property int $discount_cents
 * @property int|null $promo_code_id
 * @property string|null $promo_code
 * @property int $total_cents
 * @property string|null $locale
 * @property bool|null $marketing_opt_out
 * @property string|null $stripe_session_id
 * @property string $status
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'user_id', 'token', 'cart_id', 'order_id', 'items_snapshot', 'customer', 'shipping_address',
    'subtotal_cents', 'shipping_cents', 'discount_cents', 'promo_code_id', 'promo_code', 'total_cents',
    'locale', 'marketing_opt_out', 'stripe_session_id', 'status', 'expires_at',
])]
class Checkout extends Model
{
    /** @use HasFactory<CheckoutFactory> */
    use HasFactory;

    use Prunable;

    public const STATUSES = ['open', 'completed', 'expired'];

    /** Os dias que fica o retrato de quem nao chegou a comprar. */
    public const PRUNE_DAYS = 30;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'items_snapshot' => 'array',
            'customer' => 'array',
            'shipping_address' => 'array',
            'subtotal_cents' => 'integer',
            'shipping_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'marketing_opt_out' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Sem encomenda e com mais de 30 dias: dados pessoais de quem nao
     * comprou. Um checkout com encomenda nunca sai.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereNull('order_id')
            ->where('created_at', '<', now()->subDays(self::PRUNE_DAYS));
    }
}
