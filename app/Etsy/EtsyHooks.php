<?php

namespace App\Etsy;

use App\Jobs\DeleteEtsyListing;
use App\Jobs\PushEtsyShipment;
use App\Jobs\SyncEtsyListing;
use App\Models\EtsyListing;
use App\Models\EtsyReceipt;
use App\Models\Order;
use App\Models\Product;
use App\Models\Variant;
use Throwable;

/**
 * Os sitios onde o resto do 12studio avisa a Etsy sem ninguem pedir: o stock
 * que mexeu, o produto que foi apagado, a encomenda que foi enviada.
 *
 * Como as classes de alertas, nada daqui lanca. Quem chama esta a meio de uma
 * venda ou de um "apagar", e a Etsy em baixo nao pode estragar nem uma coisa
 * nem outra: so agendam uma tarefa, depois do commit, e a tarefa e que fala
 * com a Etsy.
 */
class EtsyHooks
{
    /** Segundos entre o stock mexer e o envio: uma encomenda mexe varias vezes. */
    public const SYNC_DELAY = 20;

    /**
     * O stock de uma variante mudou. Se o produto dela tem um anuncio que o
     * 12studio acompanha, agenda-se a sincronizacao desse anuncio.
     */
    public function stockMoved(Variant $variant): void
    {
        try {
            if (! config('etsy.enabled')) {
                return;
            }

            $listingId = EtsyListing::query()
                ->where('product_id', $variant->product_id)
                ->whereNotNull('etsy_listing_id')
                ->whereNotNull('last_pushed_at')
                ->where('sync_enabled', true)
                ->value('id');

            if ($listingId !== null) {
                SyncEtsyListing::dispatch((int) $listingId)->delay(now()->addSeconds(self::SYNC_DELAY))->afterCommit();
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Uma encomenda passou a enviada. Se veio da Etsy e a Etsy ainda nao o
     * sabe, agenda-se o aviso — uma vez.
     *
     * Nao agenda quando o envio ja la esta (marcado na Etsy e espelhado ca,
     * ou ja mandado daqui), nem quando uma tentativa anterior falhou: cada
     * aviso aceite e um email ao comprador, e o que falhou so volta a ir com
     * alguem a pedir.
     */
    public function orderShipped(Order $order): void
    {
        try {
            if (! config('etsy.enabled') || ! $order->isFromEtsy()) {
                return;
            }

            $pending = EtsyReceipt::query()
                ->where('order_id', $order->getKey())
                ->whereNull('shipped_on_etsy_at')
                ->whereNull('tracking_pushed_at')
                ->whereNull('tracking_push_error')
                ->exists();

            if ($pending) {
                PushEtsyShipment::dispatch((int) $order->getKey())->afterCommit();
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * O produto foi apagado: o anuncio dele sai da Etsy.
     */
    public function productDeleted(Product $product): void
    {
        try {
            if (! config('etsy.enabled')) {
                return;
            }

            $listingId = EtsyListing::query()
                ->where('product_id', $product->getKey())
                ->whereNotNull('etsy_listing_id')
                ->value('id');

            if ($listingId !== null) {
                DeleteEtsyListing::dispatch((int) $listingId)->afterCommit();
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
