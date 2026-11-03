<?php

namespace App\Jobs;

use App\Models\Product;
use App\Stripe\StripeCatalog;
use App\Stripe\StripeClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Poe o Product e os Prices de um produto no Stripe de acordo com o 12studio.
 *
 * Um por produto de cada vez na fila: gravar um produto com dez variantes
 * mexe nele onze vezes e so precisa de uma sincronizacao. Como na Etsy, a
 * unicidade acaba quando a tarefa COMECA, para uma mudanca a meio agendar
 * outra em vez de se perder.
 *
 * Repete-se sozinha com espera — nao ha uma varredura periodica que a apanhe.
 * O `stripe:sync-products` serve para o resto.
 */
class SyncStripeProduct implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public int $timeout = 45;

    /** Se a tarefa se perder, o cadeado nao fica para sempre. */
    public int $uniqueFor = 300;

    public function __construct(
        public int $productId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function handle(StripeCatalog $catalog, StripeClient $stripe): void
    {
        // Desligado entretanto: a tarefa ja estava na fila.
        if (! $stripe->isEnabled()) {
            return;
        }

        $product = Product::withTrashed()->find($this->productId);

        if ($product !== null) {
            $catalog->sync($product);
        }
    }
}
