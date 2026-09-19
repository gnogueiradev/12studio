<?php

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso de que foi ligada uma aplicacao (chave de API ou OAuth) a conta. Se
 * nao foi a pessoa, e por aqui que da por isso.
 */
class ApiKeyCreatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $keyName,
        public string $access,
        public ?CarbonInterface $expiresAt,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '12studio: foi ligada uma nova aplicação à tua conta',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.api-keys.created',
            with: [
                'user' => $this->user,
                'keyName' => $this->keyName,
                'accessLabel' => $this->access === 'write' ? 'leitura e escrita' : 'só leitura',
                'expiresAt' => $this->expiresAt?->format('Y-m-d'),
                'url' => route('admin.chaves-api.index'),
            ],
        );
    }
}
