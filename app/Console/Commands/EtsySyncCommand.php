<?php

namespace App\Console\Commands;

use App\Etsy\EtsyException;
use App\Etsy\EtsyListingLifecycle;
use App\Etsy\EtsyTokens;
use App\Models\EtsyListing;
use Illuminate\Console\Command;

/**
 * A rede por baixo dos ganchos: poe os anuncios da Etsy de acordo com os
 * produtos.
 *
 * O stock avisa sozinho (EtsyHooks). Isto apanha o que nao avisa ninguem —
 * um preco mudado, um nome, um produto arquivado — comparando o que iria com
 * o que foi no ultimo envio. Com --full confere primeiro os estados com a
 * Etsy e volta a tentar apagar o que ficou la de produtos apagados.
 *
 * Um anuncio que falhe fica com o erro escrito e avisa o #sistema; o comando
 * so falha se a Etsy nao responder de todo.
 */
class EtsySyncCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'etsy:sync {--full : Confere primeiro os estados com a Etsy e limpa o que ficou de produtos apagados}';

    /**
     * @var string
     */
    protected $description = 'Poe os anuncios da Etsy de acordo com os produtos do 12studio';

    public function handle(EtsyTokens $tokens, EtsyListingLifecycle $lifecycle): int
    {
        $connection = $tokens->isEnabled() ? $tokens->connection() : null;

        // Desligada, sem loja, ou com a ligacao partida (esse aviso ja saiu):
        // nao ha o que fazer, e nao e uma falha do agendador.
        if ($connection === null || $connection->isBroken()) {
            $this->info('Etsy desligada ou sem loja ligada: nada a fazer.');

            return self::SUCCESS;
        }

        if ($this->option('full')) {
            try {
                $refreshed = $lifecycle->refreshRemote();
                $this->info("Estados conferidos: {$refreshed['checked']} anúncios, {$refreshed['changed']} mudaram, {$refreshed['gone']} já não existem na Etsy.");
            } catch (EtsyException $exception) {
                $this->error("Não foi possível conferir os estados com a Etsy: {$exception->getMessage()}");

                return self::FAILURE;
            }

            $this->info("Anúncios de produtos apagados retirados da Etsy: {$lifecycle->deleteOrphans()}.");
        }

        $outcomes = [];

        EtsyListing::query()
            ->whereNotNull('etsy_listing_id')
            ->whereNotNull('last_pushed_at')
            ->where('sync_enabled', true)
            ->with('product')
            ->orderBy('id')
            ->each(function (EtsyListing $listing) use ($lifecycle, &$outcomes): void {
                $outcome = $lifecycle->sync($listing);
                $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
            });

        $this->info('Sincronização: '.($outcomes === [] ? 'nenhum anúncio acompanhado.' : implode(', ', array_map(
            fn (string $outcome, int $count): string => "{$count} {$outcome}",
            array_keys($outcomes),
            $outcomes,
        ))));

        return self::SUCCESS;
    }
}
