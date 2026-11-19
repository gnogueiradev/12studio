<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Responses\FailedPasswordResetLinkRequestResponse as FortifyFailedPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Um email que nao existe responde como um link enviado.
 *
 * A recuperacao de password so serve a equipa: a um cliente o User nao envia
 * nada, mas o broker diz "enviado". Se um email desconhecido continuasse a dar
 * "nao ha ninguem com este email", a diferenca dizia a quem pergunta que o
 * email e de um cliente da loja. As outras falhas (link pedido ha pouco, por
 * exemplo) continuam a mostrar o erro.
 */
class FailedPasswordResetLinkRequestResponse extends FortifyFailedPasswordResetLinkRequestResponse
{
    public function toResponse($request): Response
    {
        if ($this->status === Password::INVALID_USER) {
            return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_LINK_SENT])
                ->toResponse($request);
        }

        return parent::toResponse($request);
    }
}
