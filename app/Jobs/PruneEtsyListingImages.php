<?php

namespace App\Jobs;

use App\Etsy\EtsyListingSync;
use App\Models\EtsyListing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Tira do anuncio as imagens que la estavam antes de o produto mandar as suas.
 *
 * Uma so tentativa: quem repete uma criacao na Etsy sem saber se a primeira
 * chegou fica com duas. O que falhar fica escrito no anuncio e volta a
 * tentar-se no envio seguinte.
 */
class PruneEtsyListingImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(
        public int $listingId,
    ) {}

    public function handle(EtsyListingSync $sync): void
    {
        $model = EtsyListing::query()->find($this->listingId);

        if ($model !== null) {
            $sync->pruneAdoptedImages($model);
        }
    }
}
