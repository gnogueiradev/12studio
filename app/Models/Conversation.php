<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Uma conversa com um cliente da montra: contacto, interesse num produto ou
 * pedido de peca personalizada. Sem conta: o cliente entra pelo link privado
 * (ConversationToken). Quem escreve aqui e o ConversationService.
 *
 * Retencao (RGPD): uma conversa fechada apaga-se aos 24 meses; os anexos
 * saem antes, aos 6 (ConversationAttachment); o IP aos 30 dias
 * (conversations:forget-ips).
 *
 * @property int $id
 * @property string $type
 * @property string $status
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $locale
 * @property string $subject
 * @property int|null $product_id
 * @property int|null $variant_id
 * @property int|null $quantity
 * @property array<string, string>|null $personalization
 * @property array<string, mixed>|null $details
 * @property int|null $order_draft_id
 * @property Carbon|null $last_message_at
 * @property Carbon|null $admin_read_at
 * @property Carbon|null $customer_read_at
 * @property Carbon|null $consent_at
 * @property Carbon|null $closed_at
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'type', 'status', 'name', 'email', 'phone', 'locale', 'subject', 'product_id', 'variant_id',
    'quantity', 'personalization', 'details', 'order_draft_id', 'last_message_at', 'admin_read_at',
    'customer_read_at', 'consent_at', 'closed_at', 'ip_address',
])]
class Conversation extends Model
{
    use Prunable;

    public const TYPES = ['contact', 'interest', 'quote'];

    /** Aberta: espera pela loja. Respondida: espera pelo cliente. */
    public const STATUSES = ['open', 'answered', 'closed'];

    /** Meses que uma conversa fechada fica guardada. */
    public const KEEP_MONTHS = 24;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'personalization' => 'array',
            'details' => 'array',
            'last_message_at' => 'datetime',
            'admin_read_at' => 'datetime',
            'customer_read_at' => 'datetime',
            'consent_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return HasMany<ConversationMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('id');
    }

    /** @return HasMany<ConversationAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ConversationAttachment::class);
    }

    /** @return HasMany<ConversationToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(ConversationToken::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /** @return BelongsTo<OrderDraft, $this> */
    public function orderDraft(): BelongsTo
    {
        return $this->belongsTo(OrderDraft::class);
    }

    /**
     * Por ler no backoffice: aberta e com mensagem do cliente desde a ultima
     * vez que alguem a abriu.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('status', 'open')
            ->where(fn (Builder $query) => $query
                ->whereNull('admin_read_at')
                ->orWhereColumn('admin_read_at', '<', 'last_message_at'));
    }

    public function isUnread(): bool
    {
        return $this->status === 'open'
            && ($this->admin_read_at === null || ($this->last_message_at !== null && $this->admin_read_at->lt($this->last_message_at)));
    }

    /** O numero que se mostra ao cliente e no backoffice ("#0042"). */
    public function reference(): string
    {
        return '#'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $cutoff = Carbon::now()->subMonths(self::KEEP_MONTHS);

        // Fechada ha 24 meses, ou parada ha 24 meses em qualquer estado (uma
        // respondida a que o cliente nunca voltou nao fica para sempre).
        return static::query()->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('status', 'closed')->where('closed_at', '<', $cutoff))
            ->orWhere('last_message_at', '<', $cutoff));
    }

    protected function pruning(): void
    {
        Storage::disk('local')->deleteDirectory("conversations/{$this->id}");
    }
}
