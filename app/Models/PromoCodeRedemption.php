<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Um uso de um codigo. So nasce quando a encomenda fica paga.
 *
 * @property int $id
 * @property int $promo_code_id
 * @property int $order_id
 * @property string|null $email
 * @property string|null $unique_email
 * @property int $discount_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['promo_code_id', 'order_id', 'email', 'unique_email', 'discount_cents'])]
class PromoCodeRedemption extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['discount_cents' => 'integer'];
    }

    /** @return BelongsTo<PromoCode, $this> */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
