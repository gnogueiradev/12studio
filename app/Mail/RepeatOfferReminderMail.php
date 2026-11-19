<?php

namespace App\Mail;

use App\Models\PromoCode;
use App\Services\BrandSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * O lembrete da oferta para voltar a comprar, uns dias antes de o codigo
 * expirar. Como a oferta, leva a recusa de um clique no cabecalho (RFC 8058) e
 * no rodape, e da encomenda so o numero.
 */
class RepeatOfferReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public PromoCode $promoCode,
        public string $orderNumber,
        public string $unsubscribeUrl,
    ) {
        // O lembrete so fica marcado depois do commit.
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "O teu código de {$this->promoCode->value} % está quase a expirar",
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => "<{$this->unsubscribeUrl}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.promo.repeat-reminder',
            with: [
                'promoCode' => $this->promoCode,
                'orderNumber' => $this->orderNumber,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'brand' => app(BrandSettings::class)->name(),
            ],
        );
    }
}
