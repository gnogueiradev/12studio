<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Stripe\StripeCatalog;
use App\Stripe\StripeClient;
use App\Stripe\StripeException;
use Illuminate\Console\Command;

/**
 * Poe o catalogo todo no Stripe: a primeira carga, depois de ligar o Stripe,
 * e o remedio quando alguma sincronizacao automatica falhou de vez.
 *
 * Corre aqui e nao na fila: e para ser corrido a mao, a ver. Um produto que
 * falha nao para os outros.
 */
class StripeSyncProductsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'stripe:sync-products {--product=* : So estes produtos (ids)}';

    /**
     * @var string
     */
    protected $description = 'Cria ou atualiza no Stripe os produtos (Products) e as variantes (Prices)';

    public function handle(StripeClient $stripe, StripeCatalog $catalog): int
    {
        if (! $stripe->isEnabled()) {
            $this->error('O Stripe está desligado (STRIPE_ENABLED e STRIPE_SECRET). Nada foi enviado.');

            return self::FAILURE;
        }

        $ids = array_map('intval', (array) $this->option('product'));

        $products = Product::withTrashed()
            ->when($ids !== [], fn ($query) => $query->whereKey($ids))
            // Um apagado que nunca la esteve nao tem nada para arquivar.
            ->where(fn ($query) => $query->whereNull('deleted_at')->orWhereNotNull('stripe_product_id'))
            ->orderBy('id')
            ->get();

        $failed = 0;

        foreach ($products as $product) {
            try {
                $catalog->sync($product);
                $this->line("✓ {$product->name}");
            } catch (StripeException $exception) {
                $failed++;
                $this->warn("✗ {$product->name}: {$exception->getMessage()}");
            }
        }

        $this->info(sprintf('Produtos: %d. Falhados: %d.', $products->count(), $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
