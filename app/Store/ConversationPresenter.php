<?php

namespace App\Store;

use App\Models\Conversation;
use App\Models\ConversationAttachment;
use App\Models\ConversationMessage;
use App\Services\BrandSettings;
use App\Services\ConversationService;
use App\Support\LocalizedUrl;

/**
 * Uma conversa em arrays para o Inertia: a vista do cliente (sem notas
 * internas, sem dados da loja) e a do backoffice.
 */
final class ConversationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function forCustomer(Conversation $conversation, string $token): array
    {
        $conversation->load(['messages' => fn ($query) => $query->where('internal', false)->with('attachments'), 'product.images', 'variant.color', 'variant.material']);
        $base = '/conversa/'.$token;
        $brand = app(BrandSettings::class)->name();

        return [
            'reference' => $conversation->reference(),
            'type' => $conversation->type,
            'status' => $conversation->status,
            'createdAt' => $conversation->created_at?->toIso8601String(),
            'product' => self::product($conversation),
            'quantity' => $conversation->quantity,
            'personalization' => $conversation->personalization,
            'details' => $conversation->details,
            'replyUrl' => LocalizedUrl::path($base.'/responder'),
            'messages' => $conversation->messages->map(fn (ConversationMessage $message): array => [
                'id' => $message->id,
                'mine' => $message->author === 'customer',
                'author' => $message->author === 'customer' ? $conversation->name : $brand,
                'body' => $message->body,
                'createdAt' => $message->created_at?->toIso8601String(),
                'attachments' => $message->attachments->map(fn (ConversationAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'name' => $attachment->original_name,
                    'size' => $attachment->size,
                    'url' => LocalizedUrl::path($base.'/anexos/'.$attachment->id),
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forAdmin(Conversation $conversation): array
    {
        $conversation->load(['messages.attachments', 'messages.user:id,name', 'product.images', 'variant.color', 'variant.material']);

        return self::row($conversation) + [
            'phone' => $conversation->phone,
            'locale' => $conversation->locale,
            'product' => self::product($conversation, admin: true),
            'quantity' => $conversation->quantity,
            'personalization' => $conversation->personalization,
            'details' => $conversation->details,
            'orderDraftId' => $conversation->order_draft_id,
            'consentAt' => $conversation->consent_at?->toIso8601String(),
            'messages' => $conversation->messages->map(fn (ConversationMessage $message): array => [
                'id' => $message->id,
                'author' => $message->author,
                'authorName' => $message->author === 'customer' ? $conversation->name : ($message->user->name ?? 'Loja'),
                'body' => $message->body,
                'internal' => $message->internal,
                'createdAt' => $message->created_at?->toIso8601String(),
                'attachments' => $message->attachments->map(fn (ConversationAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'name' => $attachment->original_name,
                    'size' => $attachment->size,
                    'url' => route('admin.conversas.anexo', $attachment, false),
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * A linha da caixa de entrada.
     *
     * @return array<string, mixed>
     */
    public static function row(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'reference' => $conversation->reference(),
            'type' => $conversation->type,
            'status' => $conversation->status,
            'subject' => $conversation->subject,
            'name' => $conversation->name,
            'email' => $conversation->email,
            'unread' => $conversation->isUnread(),
            'lastMessageAt' => $conversation->last_message_at?->toIso8601String(),
            'createdAt' => $conversation->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function product(Conversation $conversation, bool $admin = false): ?array
    {
        $product = $conversation->product;

        if ($product === null) {
            return null;
        }

        $image = $product->images->sortBy([['is_primary', 'desc'], ['sort_order', 'asc']])->first();

        return [
            'id' => $product->id,
            'name' => $admin ? $product->name : (string) $product->translated('name', $conversation->locale),
            'href' => $admin ? route('admin.produtos.edit', $product->id, false) : LocalizedUrl::path('/produtos/'.$product->slug),
            'image' => $image?->responsive($product->name)['src'],
            'variant' => $conversation->variant === null ? null : app(ConversationService::class)->variantLabel($conversation->variant),
        ];
    }
}
