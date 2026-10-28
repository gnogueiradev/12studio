<?php

namespace App\Alerts;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Support\Str;
use Throwable;

/**
 * #conversas: uma conversa nova na montra e cada resposta de um cliente. Leva
 * o nome, o email e o inicio da mensagem, com o link para a conversa no
 * backoffice — e la que se responde.
 */
class ConversationAlerts
{
    private const TYPE_LABELS = [
        'contact' => 'Contacto',
        'interest' => 'Tenho interesse',
        'quote' => 'Peça personalizada',
    ];

    public function __construct(
        private AlertSender $sender,
    ) {}

    public function opened(Conversation $conversation): void
    {
        $this->guard(function () use ($conversation): void {
            $first = $conversation->messages()->first();
            $files = $conversation->attachments()->count();

            $message = DiscordMessage::make("💬 Conversa nova {$conversation->reference()} — {$conversation->subject}", DiscordMessage::INFO)
                ->description(Str::limit((string) $first?->body, 600))
                ->field('Tipo', self::TYPE_LABELS[$conversation->type] ?? $conversation->type)
                ->field('Cliente', "{$conversation->name} <{$conversation->email}>")
                ->field('Telefone', $conversation->phone)
                ->field('Quantidade', $conversation->quantity === null ? null : (string) $conversation->quantity)
                ->field('Idioma', strtoupper($conversation->locale))
                ->field('Anexos', $files > 0 ? (string) $files : null);

            $this->sender->send(AlertChannel::CONVERSATIONS, $message->url(route('admin.conversas.show', $conversation)));
        });
    }

    public function customerReplied(Conversation $conversation, ConversationMessage $reply): void
    {
        $this->guard(function () use ($conversation, $reply): void {
            $message = DiscordMessage::make("↩️ {$conversation->name} respondeu — {$conversation->reference()}", DiscordMessage::INFO)
                ->description(Str::limit($reply->body, 600))
                ->field('Assunto', $conversation->subject);

            $this->sender->send(AlertChannel::CONVERSATIONS, $message->url(route('admin.conversas.show', $conversation)));
        });
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
