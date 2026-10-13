<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Um recibo da Etsy e a encomenda em que se tornou.
 *
 * @property int $id
 * @property int $etsy_receipt_id
 * @property int|null $order_id
 * @property string $status
 * @property string|null $etsy_status
 * @property string|null $buyer_message
 * @property string|null $gift_message
 * @property array<string, mixed>|null $totals
 * @property array<int, array<string, mixed>>|null $unmatched_lines
 * @property Carbon|null $etsy_created_at
 * @property Carbon|null $etsy_updated_at
 * @property Carbon|null $shipped_on_etsy_at
 * @property Carbon|null $tracking_pushed_at
 * @property string|null $tracking_push_error
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order|null $order
 */
#[Fillable([
    'etsy_receipt_id', 'order_id', 'status', 'etsy_status', 'buyer_message',
    'gift_message', 'totals', 'unmatched_lines', 'etsy_created_at',
    'etsy_updated_at', 'shipped_on_etsy_at', 'tracking_pushed_at',
    'tracking_push_error', 'last_error',
])]
class EtsyReceipt extends Model
{
    public const STATUS_IMPORTED = 'imported';

    // Entrou (ou nao pode entrar) com alguma coisa que so uma pessoa resolve.
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_IMPORTED, self::STATUS_NEEDS_REVIEW, self::STATUS_FAILED];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'etsy_receipt_id' => 'integer',
            'totals' => 'array',
            'unmatched_lines' => 'array',
            'etsy_created_at' => 'datetime',
            'etsy_updated_at' => 'datetime',
            'shipped_on_etsy_at' => 'datetime',
            'tracking_pushed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Onde o dono ve esta venda no gestor da loja.
     */
    public function etsyUrl(): string
    {
        return "https://www.etsy.com/your/orders/sold?order_id={$this->etsy_receipt_id}";
    }
}
