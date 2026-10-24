<?php

namespace App\Mail;

use App\Models\User;
use App\Services\BrandSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso ao dono de cada mudanca numa conta da equipa. Vai mesmo quando foi
 * ele que clicou: e o rasto que sobra se a sessao dele for de outra pessoa.
 */
class StaffAccountChangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $staff,
        public User $by,
        public string $change,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Equipa '.app(BrandSettings::class)->name().": {$this->change} — {$this->staff->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.staff.changed',
            with: [
                'staff' => $this->staff,
                'by' => $this->by,
                'change' => $this->change,
                'url' => route('admin.utilizadores.index'),
                'brand' => app(BrandSettings::class)->name(),
            ],
        );
    }
}
