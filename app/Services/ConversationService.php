<?php

namespace App\Services;

use App\Alerts\ConversationAlerts;
use App\Mail\ConversationOpenedMail;
use App\Mail\ConversationRepliedMail;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationToken;
use App\Models\OrderDraft;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * O unico sitio que escreve conversas: abre, responde (cliente ou loja),
 * fecha, reabre e passa a rascunho de encomenda. Mexe no estado e no
 * `last_message_at`, e manda os emails e os alertas depois do commit.
 *
 * Os links privados: cada email leva um token novo de 48 caracteres, e so o
 * hash fica guardado (ConversationToken). Os tokens antigos continuam a
 * valer — o link nunca expira enquanto a conversa existir.
 */
class ConversationService
{
    public const TOKEN_LENGTH = 48;

    public function __construct(
        private ConversationAlerts $alerts,
    ) {}

    /**
     * @param  array{type: string, name: string, email: string, message: string, locale: string, phone?: string|null, product_id?: int|null, variant_id?: int|null, quantity?: int|null, personalization?: array<string, string>|null, details?: array<string, mixed>|null}  $data
     * @param  array<int, UploadedFile>  $files
     * @return array{0: Conversation, 1: string} A conversa e o token em claro (so para o email e a resposta).
     */
    public function open(array $data, array $files = [], ?string $ip = null): array
    {
        $product = isset($data['product_id']) ? Product::query()->withTrashed()->find($data['product_id']) : null;
        $variant = isset($data['variant_id']) ? Variant::query()->find($data['variant_id']) : null;

        [$conversation, $token] = DB::transaction(function () use ($data, $files, $ip, $product, $variant): array {
            $now = Carbon::now();

            $conversation = Conversation::query()->create([
                'type' => $data['type'],
                'status' => 'open',
                'name' => trim($data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                'phone' => isset($data['phone']) && trim((string) $data['phone']) !== '' ? trim((string) $data['phone']) : null,
                'locale' => $data['locale'],
                'subject' => $this->subject($data['type'], $product),
                'product_id' => $product?->id,
                'variant_id' => $variant !== null && $variant->product_id === $product?->id ? $variant->id : null,
                'quantity' => $data['quantity'] ?? null,
                'personalization' => $this->personalization($product, $data['personalization'] ?? null),
                'details' => $data['details'] ?? null,
                'last_message_at' => $now,
                'customer_read_at' => $now,
                'consent_at' => $now,
                'ip_address' => $ip,
            ]);

            $this->message($conversation, 'customer', $data['message'], null, $files);

            return [$conversation, $this->issueToken($conversation)];
        });

        Mail::to($conversation->email)->locale($conversation->locale)->queue(new ConversationOpenedMail($conversation, $token));
        $this->alerts->opened($conversation);

        return [$conversation, $token];
    }

    /**
     * Uma mensagem nova. Sem `$staff` e o cliente (reabre uma conversa
     * fechada); com `$staff` e a loja — uma resposta passa a conversa a
     * "respondida" e manda email; uma nota interna nao sai de ca.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function reply(Conversation $conversation, string $body, ?User $staff = null, array $files = [], bool $internal = false): ConversationMessage
    {
        $internal = $internal && $staff !== null;

        $message = DB::transaction(function () use ($conversation, $body, $staff, $files, $internal): ConversationMessage {
            $now = Carbon::now();
            $message = $this->message($conversation, $staff === null ? 'customer' : 'staff', $body, $staff, $files, $internal);

            if ($staff === null) {
                $conversation->forceFill([
                    'status' => 'open',
                    'closed_at' => null,
                    'last_message_at' => $now,
                    'customer_read_at' => $now,
                ])->save();
            } elseif ($internal) {
                $conversation->forceFill(['admin_read_at' => $now])->save();
            } else {
                $conversation->forceFill([
                    'status' => 'answered',
                    'closed_at' => null,
                    'last_message_at' => $now,
                    'admin_read_at' => $now,
                ])->save();
            }

            return $message;
        });

        if ($staff === null) {
            $this->alerts->customerReplied($conversation, $message);
        } elseif (! $internal) {
            $token = $this->issueToken($conversation);
            Mail::to($conversation->email)->locale($conversation->locale)->queue(new ConversationRepliedMail($conversation, $token));
        }

        return $message;
    }

    public function close(Conversation $conversation): Conversation
    {
        $conversation->forceFill(['status' => 'closed', 'closed_at' => Carbon::now()])->save();

        return $conversation;
    }

    public function reopen(Conversation $conversation): Conversation
    {
        $conversation->forceFill(['status' => 'open', 'closed_at' => null])->save();

        return $conversation;
    }

    public function markRead(Conversation $conversation): void
    {
        $conversation->forceFill(['admin_read_at' => Carbon::now()])->saveQuietly();
    }

    public function markReadByCustomer(Conversation $conversation): void
    {
        $conversation->forceFill(['customer_read_at' => Carbon::now()])->saveQuietly();
    }

    /** Um token novo para a conversa (devolve-o em claro; guarda o hash). */
    public function issueToken(Conversation $conversation): string
    {
        $token = Str::random(self::TOKEN_LENGTH);

        ConversationToken::query()->create([
            'conversation_id' => $conversation->id,
            'token_hash' => hash('sha256', $token),
            'created_at' => Carbon::now(),
        ]);

        return $token;
    }

    public function findByToken(string $token): ?Conversation
    {
        if (strlen($token) !== self::TOKEN_LENGTH) {
            return null;
        }

        return ConversationToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->first()
            ?->conversation;
    }

    /**
     * "Reenviar o meu link": um email por conversa desse endereco (as dos
     * ultimos 12 meses), cada um com um token novo. Quem chama responde
     * sempre o mesmo, haja conversas ou nao.
     */
    public function resendLinks(string $email): void
    {
        $conversations = Conversation::query()
            ->where('email', mb_strtolower(trim($email)))
            ->where('last_message_at', '>=', Carbon::now()->subYear())
            ->latest('last_message_at')
            ->limit(5)
            ->get();

        foreach ($conversations as $conversation) {
            Mail::to($conversation->email)
                ->locale($conversation->locale)
                ->queue(new ConversationOpenedMail($conversation, $this->issueToken($conversation)));
        }
    }

    /**
     * "Criar rascunho de encomenda": o cliente, a variante, a quantidade e a
     * personalizacao num rascunho do formulario de encomenda manual. Uma
     * segunda vez abre o mesmo rascunho.
     */
    public function toOrderDraft(Conversation $conversation, User $user): OrderDraft
    {
        $existing = $conversation->orderDraft;

        if ($existing !== null) {
            return $existing;
        }

        $variant = $conversation->variant()->with(['product', 'color', 'material'])->first();
        // Um produto apagado (soft delete) ja nao vem pela relacao.
        /** @var Product|null $product */
        $product = $variant?->product;
        $items = [];

        if ($variant !== null && $product !== null) {
            $items[] = [
                'variant_id' => $variant->id,
                'product_name' => $product->name,
                'variant_label' => $this->variantLabel($variant),
                'sku' => $variant->sku,
                'unit_price' => number_format(($variant->salePriceCents() ?? $variant->price_cents) / 100, 2, '.', ''),
                'qty' => max(1, (int) ($conversation->quantity ?? 1)),
                'price_override_reason' => '',
                'fulfillment_mode' => (string) $product->fulfillment_mode,
                'vat_rate' => (int) $product->vat_rate,
            ];
        }

        $note = ['Conversa '.$conversation->reference().': '.$conversation->subject];

        foreach ((array) $conversation->personalization as $key => $value) {
            $note[] = "Personalização ({$key}): {$value}";
        }

        $payload = [
            'user_id' => null,
            'customer_name' => $conversation->name,
            'email' => $conversation->email,
            'phone' => (string) $conversation->phone,
            'nif' => '',
            'sales_channel' => 'website',
            'external_order_reference' => '',
            'payment_method' => 'bank_transfer',
            'payment_status' => 'pending',
            'shipping_price' => '0',
            'shipping_method_name' => '',
            'line1' => '',
            'line2' => '',
            'postal_code' => '',
            'city' => '',
            'country' => 'PT',
            'admin_note' => implode("\n", $note),
            'send_confirmation' => false,
            'items' => $items,
        ];

        return DB::transaction(function () use ($conversation, $user, $payload, $items): OrderDraft {
            $draft = OrderDraft::query()->create([
                'created_by_user_id' => $user->id,
                'customer_name' => $conversation->name,
                'total_cents' => array_sum(array_map(
                    fn (array $item): int => (int) round(((float) $item['unit_price']) * 100) * (int) $item['qty'],
                    $items,
                )),
                'payload' => $payload,
            ]);

            $conversation->forceFill(['order_draft_id' => $draft->id])->save();

            return $draft;
        });
    }

    public function variantLabel(Variant $variant): string
    {
        return implode(' · ', array_filter([
            $variant->color?->name,
            $variant->material?->name,
            $variant->size_label,
        ]));
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function message(Conversation $conversation, string $author, string $body, ?User $user, array $files, bool $internal = false): ConversationMessage
    {
        $message = $conversation->messages()->create([
            'author' => $author,
            'user_id' => $user?->id,
            'body' => trim($body),
            'internal' => $internal,
        ]);

        foreach ($files as $file) {
            $extension = strtolower($file->getClientOriginalExtension());
            $path = $file->storeAs("conversations/{$conversation->id}", Str::uuid().'.'.$extension, 'local');

            if ($path === false) {
                throw new RuntimeException('Nao foi possivel guardar o anexo.');
            }

            $message->attachments()->create([
                'conversation_id' => $conversation->id,
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'mime' => $file->getClientMimeType(),
                'size' => (int) $file->getSize(),
            ]);
        }

        return $message;
    }

    private function subject(string $type, ?Product $product): string
    {
        return match ($type) {
            'interest' => 'Interesse: '.($product->name ?? 'produto'),
            'quote' => 'Peça personalizada',
            default => $product !== null ? 'Pergunta: '.$product->name : 'Contacto',
        };
    }

    /**
     * So os campos que o produto pede, e so texto curto.
     *
     * @param  array<mixed>|null  $values
     * @return array<string, string>|null
     */
    private function personalization(?Product $product, ?array $values): ?array
    {
        if ($product === null || $values === null) {
            return null;
        }

        // O JSON vem da BD: um item que nao seja um campo valido sai.
        /** @var array<int, mixed> $fields */
        $fields = (array) $product->personalization_fields;

        $keys = collect($fields)
            ->map(fn (mixed $field): mixed => is_array($field) ? ($field['key'] ?? null) : null)
            ->filter(fn (mixed $key): bool => is_string($key))
            ->all();

        $clean = [];

        foreach ($keys as $key) {
            $value = $values[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                $clean[$key] = mb_substr(trim((string) $value), 0, 100);
            }
        }

        return $clean === [] ? null : $clean;
    }
}
