<?php

namespace App\Console\Commands;

use App\Customers\RepeatOffers;
use App\Services\StoreSettings;
use App\Shop\ShopGate;
use Illuminate\Console\Command;

/**
 * Manda as ofertas para voltar a comprar e os lembretes (RepeatOffers). Corre
 * de hora a hora; cada encomenda recebe uma so oferta e cada oferta um so
 * lembrete, corra isto as vezes que correr.
 *
 * Com as ofertas desligadas, ou sem a loja a vender ao publico, sai logo: um
 * codigo que nao se pode usar e so ruido na caixa de correio do cliente.
 */
class SendRepeatOffersCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'offers:send';

    /**
     * @var string
     */
    protected $description = 'Manda as ofertas para voltar a comprar e os lembretes antes de expirarem';

    public function handle(RepeatOffers $offers, StoreSettings $settings, ShopGate $gate): int
    {
        if (! $settings->offers()['enabled'] || ! $gate->allows(null)) {
            $this->info('Ofertas desligadas ou loja fechada: nada a mandar.');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($offers->due() as $order) {
            $offers->send($order);
            $sent++;
        }

        $reminded = 0;
        foreach ($offers->remindersDue() as $offer) {
            $offers->remind($offer);
            $reminded++;
        }

        // So contagens: nada de emails no output, que vai para o Discord se
        // a tarefa falhar.
        $this->info("Ofertas: {$sent}. Lembretes: {$reminded}.");

        return self::SUCCESS;
    }
}
