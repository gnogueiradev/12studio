<?php

namespace App\Console\Commands;

use App\Models\EtsyReceipt;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemStatusHistory;
use App\Models\OrderStatusHistory;
use App\Models\PromoCodeRedemption;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tira os dados pessoais das encomendas que ja passaram o prazo fiscal. A lei
 * obriga a guardar os documentos durante os 10 anos civis seguintes ao da
 * venda (CIVA, art. 52.º), e a politica de privacidade promete que depois
 * saem. A encomenda fica — numero, pecas, valores, fatura — para as contas e
 * o historico de vendas; sai quem comprou e o que escreveu.
 *
 * Anonimiza e nao apaga: as linhas de stock, os codigos de desconto e os
 * recibos da Etsy apontam para a encomenda.
 *
 * O prazo conta da ultima alteracao (updated_at) e nao da criacao: um
 * pagamento ou um reembolso tardio empurram a fatura, e empurram o prazo. A
 * propria anonimizacao toca no updated_at, por isso nenhuma encomenda e
 * apanhada duas vezes. Escreve pela query e nao pelo modelo: nao ha historico,
 * alertas nem emails a disparar.
 */
class AnonymizeOldOrdersCommand extends Command
{
    public const CUSTOMER_NAME = 'Cliente anonimizado';

    private const YEARS = 10;

    /**
     * @var string
     */
    protected $signature = 'orders:anonymize';

    /**
     * @var string
     */
    protected $description = 'Tira os dados pessoais das encomendas com mais de 10 anos civis (RGPD)';

    public function handle(): int
    {
        $cutoff = now()->startOfYear()->subYears(self::YEARS);
        $count = 0;

        Order::query()
            ->where('updated_at', '<', $cutoff)
            ->select('id')
            ->chunkById(200, function (Collection $orders) use (&$count): void {
                $ids = $orders->modelKeys();
                DB::transaction(fn () => $this->anonymize($ids));
                $count += count($ids);
            });

        $this->info("Dados pessoais retirados de {$count} encomenda(s) anteriores a {$cutoff->year}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    private function anonymize(array $ids): void
    {
        Order::query()->whereKey($ids)->update([
            'customer_name' => self::CUSTOMER_NAME,
            'user_id' => null,
            'email' => null,
            'phone' => null,
            'nif' => null,
            'shipping_address' => null,
            'billing_address' => null,
            'tracking_number' => null,
            'tracking_url' => null,
            'admin_note' => null,
        ]);

        $items = OrderItem::query()->whereIn('order_id', $ids);

        OrderItemStatusHistory::query()
            ->whereIn('order_item_id', (clone $items)->select('id'))
            ->whereNotNull('note')
            ->update(['note' => null]);

        $items->whereNotNull('personalization')->update(['personalization' => null]);

        OrderStatusHistory::query()->whereIn('order_id', $ids)->whereNotNull('note')->update(['note' => null]);

        PromoCodeRedemption::query()->whereIn('order_id', $ids)->update(['email' => null, 'unique_email' => null]);

        EtsyReceipt::query()->whereIn('order_id', $ids)->update(['buyer_message' => null, 'gift_message' => null]);
    }
}
