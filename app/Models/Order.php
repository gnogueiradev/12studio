<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $order_number
 * @property int|null $user_id
 * @property string $customer_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $nif
 * @property string $status
 * @property string|null $payment_method
 * @property string $payment_status
 * @property string $sales_channel
 * @property string|null $external_order_reference
 * @property int|null $created_by_user_id
 * @property string|null $stripe_session_id
 * @property string|null $stripe_payment_intent_id
 * @property int $subtotal_cents
 * @property int $shipping_cents
 * @property int $total_cents
 * @property string $currency
 * @property array<string, mixed>|null $shipping_address
 * @property array<string, mixed>|null $billing_address
 * @property string|null $shipping_method_name
 * @property string|null $tracking_number
 * @property string|null $tracking_url
 * @property Carbon|null $paid_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $cancelled_at
 * @property string|null $admin_note
 * @property bool $stock_issue
 * @property string $guest_access_token
 * @property string|null $invoice_provider
 * @property string|null $invoice_external_id
 * @property string|null $invoice_number
 * @property string|null $invoice_url
 * @property Carbon|null $invoiced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'order_number', 'user_id', 'customer_name', 'email', 'phone', 'nif',
    'status', 'payment_method', 'payment_status', 'sales_channel',
    'external_order_reference', 'created_by_user_id', 'stripe_session_id',
    'stripe_payment_intent_id', 'subtotal_cents', 'shipping_cents',
    'total_cents', 'currency', 'shipping_address', 'billing_address',
    'shipping_method_name', 'tracking_number', 'tracking_url', 'paid_at',
    'shipped_at', 'delivered_at', 'cancelled_at', 'admin_note', 'stock_issue',
    'guest_access_token', 'invoice_provider', 'invoice_external_id',
    'invoice_number', 'invoice_url', 'invoiced_at',
])]
#[Hidden(['guest_access_token'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    // Pipeline de fulfilment. O indicador FINANCEIRO e payment_status;
    // as invariantes entre os dois vivem no OrderService (Fase 4) e sao a
    // UNICA via de transicao — nunca validar so na interface.
    public const STATUSES = [
        'pending_payment', 'paid', 'in_production', 'ready_to_ship',
        'shipped', 'delivered', 'cancelled', 'refunded',
    ];

    public const PAYMENT_STATUSES = [
        'pending', 'paid', 'partially_refunded', 'refunded', 'failed',
    ];

    public const PAYMENT_METHODS = [
        'card', 'multibanco', 'mbway', 'cash', 'bank_transfer', 'vinted', 'other',
    ];

    public const SALES_CHANNELS = ['website', 'vinted', 'instagram', 'manual'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'shipping_cents' => 'integer',
            'total_cents' => 'integer',
            'shipping_address' => 'array',
            'billing_address' => 'array',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'stock_issue' => 'boolean',
            'invoiced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /** @return HasMany<StockReservation, $this> */
    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isGuest(): bool
    {
        return $this->user_id === null;
    }
}
