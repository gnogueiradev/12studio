<?php

namespace App\Jobs;

use App\Etsy\EtsyListingLifecycle;
use App\Models\EtsyListing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Poe um anuncio da Etsy de acordo com o produto, depois de o stock mexer.
 *
 * Um por anuncio de cada vez na fila: uma encomenda de tres linhas mexe no
 * stock tres vezes e so precisa de um envio. A unicidade acaba quando a
 * tarefa COMECA (e nao quando termina), para o stock que mexer a meio de um
 * envio agendar outro em vez de se perder.
 *
 * Nao lanca nem repete: o que falhar fica escrito no anuncio, e o etsy:sync
 * de 15 em 15 minutos volta a tentar.
 */
class SyncEtsyListing implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    /** Se a tarefa se perder, o cadeado nao fica para sempre. */
    public int $uniqueFor = 300;

    public function __construct(
        public int $listingId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->listingId;
    }

    public function handle(EtsyListingLifecycle $lifecycle): void
    {
        $listing = EtsyListing::query()->find($this->listingId);

        if ($listing !== null) {
            $lifecycle->sync($listing);
        }
    }
}
