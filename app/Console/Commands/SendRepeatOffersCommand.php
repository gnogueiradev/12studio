<?php

namespace App\Console\Commands;

use App\Customers\RepeatOffers;
use App\Services\StoreSettings;
use App\Shop\ShopGate;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

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

        // Uma oferta que falhe fica no relatorio de erros e as outras saem
        // na mesma. A excecao reportada e outra, so com o numero e a classe:
        // a original pode trazer o email do cliente (o SMTP, a BD), e o
        // relatorio de erros vai para o Discord.
        $sent = 0;
        foreach ($offers->due() as $order) {
            try {
                $offers->send($order);
                $sent++;
            } catch (Throwable $exception) {
                report(new RuntimeException("oferta da encomenda #{$order->id} falhou: ".class_basename($exception)));
            }
        }

        $reminded = 0;
        foreach ($offers->remindersDue() as $offer) {
            try {
                $offers->remind($offer);
                $reminded++;
            } catch (Throwable $exception) {
                report(new RuntimeException("lembrete da oferta da encomenda #{$offer->order_id} falhou: ".class_basename($exception)));
            }
        }

        // So contagens: nada de emails no output, que vai para o Discord se
        // a tarefa falhar.
        $this->info("Ofertas: {$sent}. Lembretes: {$reminded}.");

        return self::SUCCESS;
    }
}
