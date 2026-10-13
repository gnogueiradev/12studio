<?php

namespace App\Jobs;

use App\Etsy\EtsyShipments;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Diz a Etsy que uma encomenda dela foi enviada.
 *
 * Uma so tentativa, e de proposito: cada envio que a Etsy aceita e um email
 * ao comprador, e repetir sem saber se o primeiro chegou eram dois. O que
 * falhar fica no recibo, avisa o dono, e so volta a ir com alguem a pedir.
 */
class PushEtsyShipment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(
        public int $orderId,
    ) {}

    public function handle(EtsyShipments $shipments): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order !== null) {
            $shipments->pushQuietly($order);
        }
    }
}
