<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Uma mensagem de uma conversa. O corpo e texto simples (escapado ao
 * mostrar). As notas internas (`internal`) nunca chegam ao cliente.
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $author
 * @property int|null $user_id
 * @property string $body
 * @property bool $internal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['conversation_id', 'author', 'user_id', 'body', 'internal'])]
class ConversationMessage extends Model
{
    public const AUTHORS = ['customer', 'staff'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'internal' => 'boolean',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ConversationAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ConversationAttachment::class);
    }
}
