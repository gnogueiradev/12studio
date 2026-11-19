<?php

namespace App\Alerts;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use Throwable;

/**
 * #conversas: uma conversa nova na montra e cada resposta de um cliente. Leva
 * a referencia, o assunto (que a loja gera) e o link para a conversa no
 * backoffice — e la que se le e responde.
 *
 * Do cliente nao sai nada: nem nome, email ou telefone, nem o texto da
 * mensagem, que e livre e pode trazer dados pessoais. O Discord e um terceiro
 * fora da UE (decisao do dono, 2026-11-19).
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
            $files = $conversation->attachments()->count();

            $message = DiscordMessage::make("💬 Conversa nova {$conversation->reference()} — {$conversation->subject}", DiscordMessage::INFO)
                ->field('Tipo', self::TYPE_LABELS[$conversation->type] ?? $conversation->type)
                ->field('Quantidade', $conversation->quantity === null ? null : (string) $conversation->quantity)
                ->field('Idioma', strtoupper($conversation->locale))
                ->field('Anexos', $files > 0 ? (string) $files : null);

            $this->sender->send(AlertChannel::CONVERSATIONS, $message->url(route('admin.conversas.show', $conversation)));
        });
    }

    public function customerReplied(Conversation $conversation, ConversationMessage $reply): void
    {
        $this->guard(function () use ($conversation): void {
            $message = DiscordMessage::make("↩️ O cliente respondeu — {$conversation->reference()}", DiscordMessage::INFO)
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
