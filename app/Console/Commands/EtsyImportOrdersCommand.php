<?php

namespace App\Console\Commands;

use App\Alerts\SystemAlerts;
use App\Etsy\EtsyException;
use App\Etsy\EtsyOrderImporter;
use App\Etsy\EtsyTokens;
use Illuminate\Console\Command;

/**
 * Traz as encomendas novas da Etsy, de 5 em 5 minutos.
 *
 * Se a Etsy nao responder, o comando nao falha: de 5 em 5 minutos isso era um
 * aviso do agendador por cada tentativa. Avisa uma vez (SystemAlerts) e a
 * tentativa seguinte retoma de onde ficou — o cursor so anda com o que foi
 * mesmo lido.
 */
class EtsyImportOrdersCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'etsy:import-orders';

    /**
     * @var string
     */
    protected $description = 'Importa as encomendas novas da Etsy e acompanha as que ja entraram';

    public function handle(EtsyTokens $tokens, EtsyOrderImporter $importer, SystemAlerts $alerts): int
    {
        $connection = $tokens->isEnabled() ? $tokens->connection() : null;

        if ($connection === null || $connection->isBroken()) {
            $this->info('Etsy desligada ou sem loja ligada: nada a importar.');

            return self::SUCCESS;
        }

        try {
            $result = $importer->run();
        } catch (EtsyException $exception) {
            $alerts->etsyImportFailed($exception->getMessage());
            $this->warn("A Etsy não respondeu: {$exception->getMessage()}");

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Recibos vistos: %d. Encomendas novas: %d. Atualizadas: %d. Por rever: %d. Falhadas: %d.',
            $result['fetched'],
            $result['imported'],
            $result['updated'],
            $result['review'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
