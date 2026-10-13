<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Etsy\EtsyException;
use Closure;
use Illuminate\Http\RedirectResponse;

/**
 * Para os controladores que escrevem na Etsy: faz, e diz como correu.
 *
 * O que a Etsy recusar volta para o ecra como um aviso com as palavras dela —
 * nunca como um erro 500 a meio do backoffice.
 */
trait AttemptsEtsy
{
    /**
     * @param  Closure(): (string|null)  $action  Devolve a mensagem de sucesso, ou null para usar $success.
     */
    protected function attempt(Closure $action, string $success): RedirectResponse
    {
        try {
            $this->toast($action() ?? $success);
        } catch (EtsyException $exception) {
            $this->toast($exception->getMessage(), 'error');
        }

        return back();
    }
}
