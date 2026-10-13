<?php

namespace App\Mcp\Tools\Etsy\Concerns;

use Laravel\Mcp\Response;

/**
 * A pergunta antes de mexer no que os compradores estao a ver, ou de apagar.
 *
 * O mesmo padrao do PriceGuard: a primeira chamada nao faz nada e diz o que
 * esta em jogo; so com "confirm": true — depois de o Claude o ter mostrado ao
 * dono — e que a ferramenta escreve na Etsy.
 */
trait AsksBeforeEtsy
{
    protected function askFirst(string $whatIsAtStake): Response
    {
        return Response::error(
            rtrim($whatIsAtStake).' Nada foi alterado na Etsy. Mostra isto ao utilizador e, só se ele confirmar, repete com "confirm": true.',
        );
    }

    protected function activeListings(int $count): string
    {
        return $count === 1 ? '1 anúncio ativo' : "{$count} anúncios ativos";
    }
}
