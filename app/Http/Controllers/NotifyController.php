<?php

namespace App\Http\Controllers;

use App\Http\Requests\Notify\StoreNotifyRequest;
use App\Models\NotifySubscription;
use Illuminate\Http\RedirectResponse;

class NotifyController extends Controller
{
    /**
     * Guarda o email deixado na landing "em breve".
     *
     * firstOrCreate e nao create: um segundo envio do mesmo email nao pode
     * rebentar contra o indice unico, nem responder "este email ja esta
     * registado" — isso transformava a landing num oraculo de quem subscreveu.
     * Repetir e silenciosamente idempotente.
     */
    public function __invoke(StoreNotifyRequest $request): RedirectResponse
    {
        NotifySubscription::firstOrCreate([
            'email' => $request->validated('email'),
        ]);

        return back();
    }
}
