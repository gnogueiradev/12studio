<?php

namespace App\Mcp\Tools\Etsy\Concerns;

use App\Etsy\EtsyException;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Para as ferramentas que falam com a Etsy: escrevem o `etsy()` em vez do
 * `run()`, e o que a Etsy recusar chega ao Claude por palavras.
 *
 * Sem isto, uma EtsyException caia no "Erro interno" generico da StudioTool —
 * que e o que se quer para um bug nosso, e o contrario do que se quer para um
 * "Invalid taxonomy_id" ou um "ainda nao ha loja ligada", que dizem ao Claude
 * exatamente o que fazer a seguir.
 */
trait TalksToEtsy
{
    abstract protected function etsy(Request $request, User $user): Response|ResponseFactory;

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        try {
            return $this->etsy($request, $user);
        } catch (EtsyException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
