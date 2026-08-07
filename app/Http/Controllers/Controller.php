<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

abstract class Controller
{
    /**
     * Mensagem flash para o Toaster do frontend.
     *
     * O hook `useFlashToast` escuta o evento `flash` do Inertia e le
     * `flash.toast` — um `->with('success', ...)` de sessao NUNCA chega la.
     *
     * @param  'success'|'info'|'warning'|'error'  $type
     */
    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
