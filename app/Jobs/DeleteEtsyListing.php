<?php

namespace App\Jobs;

use App\Etsy\EtsyListingLifecycle;
use App\Models\EtsyListing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Apaga da Etsy o anuncio de um produto que foi apagado ca.
 *
 * Nao lanca: numa fila sync a excecao subia ate ao "apagar produto", que ja
 * aconteceu. Se a Etsy falhar, o anuncio fica la, o erro fica escrito nele e
 * no #sistema, e o etsy:sync da noite volta a tentar.
 */
class DeleteEtsyListing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(
        public int $listingId,
    ) {}

    public function handle(EtsyListingLifecycle $lifecycle): void
    {
        $listing = EtsyListing::query()->find($this->listingId);

        // Recuperado entretanto (apagado por engano): ja nao ha o que apagar.
        if ($listing !== null && $listing->product->trashed()) {
            $lifecycle->deleteQuietly($listing);
        }
    }
}
