<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Encomenda manual guardada a meio, no formato em que o formulario a envia.
 *
 * O `payload` e a autoridade; `customer_name` e `total_cents` sao copias para
 * a listagem nao ter de abrir o JSON de cada linha.
 *
 * @property int $id
 * @property int $created_by_user_id
 * @property string|null $customer_name
 * @property int $total_cents
 * @property array<string, mixed> $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['created_by_user_id', 'customer_name', 'total_cents', 'payload'])]
class OrderDraft extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_cents' => 'integer',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
