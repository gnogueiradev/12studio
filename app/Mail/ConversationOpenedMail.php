<?php

namespace App\Mail;

use App\Services\BrandSettings;
use Illuminate\Mail\Mailables\Content;

/** Para o cliente: a conversa esta aberta, e este e o link para a seguir. */
class ConversationOpenedMail extends ConversationMail
{
    protected function subjectKey(): string
    {
        return 'store.mail.opened_subject';
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.conversations.opened',
            with: [
                'conversation' => $this->conversation,
                'url' => $this->url(),
                'brand' => app(BrandSettings::class)->name(),
            ],
        );
    }
}
