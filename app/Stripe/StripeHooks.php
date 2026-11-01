<?php

namespace App\Stripe;

use App\Jobs\ExpireStripeSession;
use App\Jobs\SyncStripeProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\Variant;
use Throwable;

/**
 * Os sitios onde o catalogo avisa o Stripe sem ninguem pedir: um produto ou
 * uma variante que mudou naquilo que o Stripe mostra.
 *
 * Como os EtsyHooks, nada daqui lanca: quem chama esta a meio de gravar um
 * produto, e o Stripe em baixo nao pode estragar a gravacao. So agenda uma
 * tarefa, depois do commit.
 */
class StripeHooks
{
    /** Segundos entre a mudanca e o envio: gravar um produto mexe nele varias vezes. */
    public const SYNC_DELAY = 10;

    /** O que o Stripe mostra de um produto. O resto (stock, SEO, custos) nao lhe interessa. */
    public const PRODUCT_FIELDS = ['name', 'description', 'status', 'deleted_at'];

    /** O que o Stripe guarda de uma variante. */
    public const VARIANT_FIELDS = ['price_cents', 'active'];

    public function __construct(
        private StripeClient $stripe,
    ) {}

    public function productChanged(Product $product): void
    {
        $this->schedule((int) $product->getKey());
    }

    public function variantChanged(Variant $variant): void
    {
        $this->schedule((int) $variant->product_id);
    }

    /**
     * A encomenda ja nao se paga pelo que a sessao aberta diz: foi paga a mao,
     * cancelada, ou mudou de valor. Um link mandado por DM dura 24 horas, e sem
     * isto continuava a cobrar.
     *
     * Com um payment intent, o cliente ja submeteu e nao ha nada a expirar.
     */
    public function sessionOutdated(Order $order): void
    {
        try {
            if (! $this->stripe->isEnabled() || $order->stripe_session_id === null || $order->stripe_payment_intent_id !== null) {
                return;
            }

            ExpireStripeSession::dispatch((int) $order->getKey(), $order->stripe_session_id)->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function schedule(int $productId): void
    {
        try {
            if (! $this->stripe->isEnabled()) {
                return;
            }

            SyncStripeProduct::dispatch($productId)->delay(now()->addSeconds(self::SYNC_DELAY))->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
