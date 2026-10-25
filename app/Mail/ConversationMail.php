<?php

namespace App\Mail;

use App\Models\Conversation;
use App\Services\BrandSettings;
use App\Services\StoreSettings;
use App\Support\LocalizedUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Base dos emails de uma conversa. Levam o link privado (o token em claro),
 * por isso a fila guarda-os encriptados. O idioma e o da conversa (quem envia
 * poe-no com ->locale()), e o "responder" vai para o email da loja.
 */
abstract class ConversationMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public string $token,
    ) {
        $this->afterCommit();
    }

    abstract protected function subjectKey(): string;

    public function envelope(): Envelope
    {
        $reply = app(StoreSettings::class)->toForm()['contact_email'];

        return new Envelope(
            subject: trans($this->subjectKey(), [
                'store' => app(BrandSettings::class)->name(),
                'reference' => $this->conversation->reference(),
            ]),
            replyTo: $reply !== '' ? [new Address($reply, app(BrandSettings::class)->name())] : [],
        );
    }

    /** O link da conversa, no idioma dela. */
    protected function url(): string
    {
        return url(LocalizedUrl::path('/conversa/'.$this->token, $this->conversation->locale));
    }
}
