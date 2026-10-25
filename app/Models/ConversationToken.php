<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Um link privado de uma conversa. So o hash (sha256) fica guardado.
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $token_hash
 * @property Carbon|null $created_at
 */
#[Fillable(['conversation_id', 'token_hash', 'created_at'])]
class ConversationToken extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
