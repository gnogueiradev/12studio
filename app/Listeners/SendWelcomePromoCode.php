<?php

namespace App\Listeners;

use App\Jobs\IssueWelcomePromoCode;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Quem prova o email pela primeira vez (o primeiro link de entrada,
 * CustomerLoginLinks) recebe um codigo pessoal. A equipa nunca, e quem ja
 * comprou com aquele email tambem nao: esse fica para as ofertas de voltar a
 * comprar.
 */
class SendWelcomePromoCode implements ShouldQueue
{
    public function handle(Registered $event): void
    {
        $user = $event->user;
        $email = $user instanceof User ? PromoCode::normalizeEmail($user->email) : null;

        if (! $user instanceof User || $email === null || $user->isStaff()) {
            return;
        }

        $alreadyBought = Order::query()
            ->where('payment_status', 'paid')
            ->whereRaw('lower(email) = ?', [$email])
            ->exists();

        if ($alreadyBought) {
            return;
        }

        IssueWelcomePromoCode::dispatch($user->getKey(), $email);
    }
}
