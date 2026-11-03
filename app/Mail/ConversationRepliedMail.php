<?php

namespace App\Mail;

use App\Services\BrandSettings;
use Illuminate\Mail\Mailables\Content;

/** Para o cliente: a loja respondeu. O texto fica no link, nao no email. */
class ConversationRepliedMail extends ConversationMail
{
    protected function subjectKey(): string
    {
        return 'store.mail.replied_subject';
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.conversations.replied',
            with: [
                'conversation' => $this->conversation,
                'url' => $this->url(),
                'brand' => app(BrandSettings::class)->name(),
            ],
        );
    }
}
