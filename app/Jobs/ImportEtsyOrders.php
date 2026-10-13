<?php

namespace App\Jobs;

use App\Alerts\SystemAlerts;
use App\Etsy\EtsyException;
use App\Etsy\EtsyOrderImporter;
use App\Etsy\EtsyTokens;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A importacao de encomendas da Etsy, pedida por um aviso dela (webhook).
 *
 * E a mesma que o agendador corre de 5 em 5 minutos; isto so a adianta. Uma
 * so na fila de cada vez: tres avisos seguidos (paga, enviada, entregue) nao
 * precisam de tres importacoes, e a que corre traz tudo o que mudou. A
 * unicidade acaba quando comeca, para um aviso que chegue a meio agendar
 * outra em vez de se perder.
 *
 * Nao lanca nem repete: se a Etsy nao responder, a do agendador volta a
 * tentar dai a nada.
 */
class ImportEtsyOrders implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    /** Se a tarefa se perder, o cadeado nao fica para sempre. */
    public int $uniqueFor = 120;

    public function handle(EtsyTokens $tokens, EtsyOrderImporter $importer, SystemAlerts $alerts): void
    {
        $connection = $tokens->isEnabled() ? $tokens->connection() : null;

        if ($connection === null || $connection->isBroken()) {
            return;
        }

        try {
            $importer->run();
        } catch (EtsyException $exception) {
            $alerts->etsyImportFailed($exception->getMessage());
        }
    }
}
