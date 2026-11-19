<?php

namespace App\Mail;

use App\Services\BrandSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * O link para entrar na conta de cliente. Leva o segredo em claro, por isso a
 * fila guarda-o encriptado, como os emails das conversas.
 */
class CustomerLoginLinkMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $url,
    ) {
        // O link so funciona depois do commit que guardou o token.
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'O teu link para entrar na '.app(BrandSettings::class)->name(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.customers.login-link',
            with: [
                'url' => $this->url,
                'brand' => app(BrandSettings::class)->name(),
            ],
        );
    }
}
