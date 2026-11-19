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
 * A oferta para voltar a comprar. E marketing: leva a recusa de um clique no
 * cabecalho (RFC 8058, o "cancelar subscricao" do Gmail e afins) e no rodape.
 * Da encomenda so vai o numero.
 */
class RepeatOfferMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public PromoCode $promoCode,
        public string $orderNumber,
        public string $unsubscribeUrl,
    ) {
        // O codigo so existe depois do commit.
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Obrigado pela tua compra: {$this->promoCode->value} % na próxima",
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
            markdown: 'mail.promo.repeat',
            with: [
                'promoCode' => $this->promoCode,
                'orderNumber' => $this->orderNumber,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'brand' => app(BrandSettings::class)->name(),
            ],
        );
    }
}
