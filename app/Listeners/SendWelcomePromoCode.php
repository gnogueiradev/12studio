<?php

namespace App\Listeners;

use App\Jobs\IssueWelcomePromoCode;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Quem cria conta recebe um codigo pessoal. O registo fica desligado ate a
 * montra ter contas de cliente (config/fortify.php): ate la, nada dispara isto.
 */
class SendWelcomePromoCode implements ShouldQueue
{
    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || blank($user->email) || $user->isAdmin()) {
            return;
        }

        IssueWelcomePromoCode::dispatch($user->getKey(), $user->email);
    }
}
