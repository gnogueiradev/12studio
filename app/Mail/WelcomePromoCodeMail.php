<?php

namespace App\Mail;

use App\Models\PromoCode;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Ao contrario dos outros, nao vai para a fila: quem o manda ja e um job, e uma
 * falha tem de voltar a ele para as tentativas contarem.
 */
class WelcomePromoCodeMail extends Mailable
{
    public function __construct(
        public PromoCode $promoCode,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tens um desconto de boas-vindas na 12studio',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.promo.welcome',
            with: [
                'promoCode' => $this->promoCode,
            ],
        );
    }
}
