<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Um ficheiro enviado numa conversa (modelo 3D, fotografia, PDF). Fica no
 * disco privado e so se descarrega com o link da conversa ou como admin.
 * Sai seis meses depois de a conversa fechar — a conversa fica.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $conversation_message_id
 * @property string $path
 * @property string $original_name
 * @property string|null $mime
 * @property int $size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['conversation_id', 'conversation_message_id', 'path', 'original_name', 'mime', 'size'])]
class ConversationAttachment extends Model
{
    use Prunable;

    /** Meses que os anexos ficam depois de a conversa fechar. */
    public const KEEP_MONTHS = 6;

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<ConversationMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'conversation_message_id');
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $cutoff = Carbon::now()->subMonths(self::KEEP_MONTHS);

        return static::query()->whereHas('conversation', fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('status', 'closed')->where('closed_at', '<', $cutoff))
            ->orWhere('last_message_at', '<', $cutoff));
    }

    protected function pruning(): void
    {
        Storage::disk('local')->delete($this->path);
    }
}
