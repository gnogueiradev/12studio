<?php

namespace App\Etsy;

use App\Alerts\OrderAlerts;
use App\Models\AlertState;
use App\Models\EtsyConnection;
use App\Models\EtsyReceipt;
use App\Models\EtsyVariant;
use App\Models\Order;
use App\Models\Variant;
use App\Services\OrderService;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * As encomendas da Etsy a entrarem no 12studio.
 *
 * Pergunta a Etsy pelos recibos que mudaram desde a ultima vez e trata cada
 * um: o que e novo e esta pago torna-se uma encomenda (com o stock a descer);
 * o que ja ca esta acompanha o que aconteceu la — cancelado, reembolsado,
 * enviado.
 *
 * O que segura tudo e o `etsy_receipt_id` unico: correr isto duas vezes, ou
 * dois processos ao mesmo tempo, nunca da duas encomendas nem desce o stock
 * duas vezes. Um recibo que falhe nao trava os outros.
 *
 * So entra o que foi vendido DEPOIS de a loja ser ligada: o que veio antes ja
 * foi tratado a mao, e importa-lo descia o stock outra vez.
 *
 * @phpstan-import-type MappedReceipt from EtsyReceiptMapper
 * @phpstan-import-type MappedLine from EtsyReceiptMapper
 */
class EtsyOrderImporter
{
    /** Onde fica o "ja vi ate aqui" (alert_states). */
    public const CURSOR = 'etsy:receipts:last_modified';

    private const LOCK = 'etsy:import-orders';

    private const EMPTY = ['fetched' => 0, 'imported' => 0, 'updated' => 0, 'review' => 0, 'failed' => 0];

    public function __construct(
        private EtsyApi $api,
        private EtsyTokens $tokens,
        private EtsyReceiptMapper $mapper,
        private OrderService $orders,
        private OrderAlerts $alerts,
    ) {}

    /**
     * Lanca EtsyException se a Etsy nao responder a lista; quem chama decide
     * o que fazer com isso.
     *
     * @return array{fetched: int, imported: int, updated: int, review: int, failed: int}
     */
    public function run(): array
    {
        $connection = $this->tokens->connection() ?? throw EtsyException::notConnected();

        // Uma importacao de cada vez. Se ja ha uma a correr, esta nao tem
        // nada a acrescentar: a outra traz tudo.
        $lock = Cache::lock(self::LOCK, 120);

        if (! $lock->get()) {
            return self::EMPTY;
        }

        try {
            return $this->import($connection);
        } finally {
            $lock->release();
        }
    }

    /**
     * Quando correu pela ultima vez com novidades, para o ecra o dizer.
     */
    public function cursor(): ?int
    {
        $cursor = AlertState::watermark(self::CURSOR);

        return is_numeric($cursor) ? (int) $cursor : null;
    }

    /**
     * @return array{fetched: int, imported: int, updated: int, review: int, failed: int}
     */
    private function import(EtsyConnection $connection): array
    {
        $result = self::EMPTY;
        $stored = $this->cursor();
        $cursor = $stored ?? $connection->orders_since->timestamp ?? now()->timestamp;
        $latest = $cursor;
        $limit = max(1, min(100, (int) config('etsy.orders.page_size', 100)));
        $offset = 0;

        do {
            $page = EtsyPresenter::rows($this->api->receipts($connection->shop_id, [
                'min_last_modified' => $cursor,
                'sort_on' => 'updated',
                'sort_order' => 'asc',
                'limit' => $limit,
                'offset' => $offset,
            ])['results'] ?? null);

            foreach ($page as $receipt) {
                $result['fetched']++;
                $latest = max($latest, (int) ($receipt['update_timestamp'] ?? $receipt['updated_timestamp'] ?? 0));

                try {
                    $outcome = $this->importReceipt($receipt, $connection);
                } catch (Throwable $exception) {
                    // Um recibo torto nao trava os que vem a seguir.
                    report($exception);
                    $outcome = 'failed';
                }

                if ($outcome !== null) {
                    $result[$outcome]++;
                }
            }

            $offset += $limit;
        } while (count($page) === $limit);

        // Uma sondagem sem novidades nao escreve nada.
        if ($latest !== $stored) {
            AlertState::setWatermark(self::CURSOR, (string) $latest);
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $receipt
     * @return 'imported'|'updated'|'review'|'failed'|null Null: nada a fazer.
     */
    private function importReceipt(array $receipt, EtsyConnection $connection): ?string
    {
        $data = $this->mapper->map($receipt);

        if ($data['receipt_id'] === 0) {
            return null;
        }

        $row = EtsyReceipt::query()->where('etsy_receipt_id', $data['receipt_id'])->first();

        if ($row !== null) {
            return $this->follow($row, $data);
        }

        // Por pagar, cancelado ou devolvido antes de alguma vez ca ter
        // entrado: nao ha encomenda para fazer. Quando for pago, a Etsy mexe
        // no recibo e ele volta a aparecer.
        if (! $data['is_paid'] || $data['is_canceled'] || $data['refund'] === 'full') {
            return null;
        }

        if ($data['created_at'] !== null && $connection->orders_since !== null && $data['created_at']->lt($connection->orders_since)) {
            return null;
        }

        if ($data['lines'] === []) {
            return $this->park($data, EtsyReceipt::STATUS_FAILED, 'O recibo veio sem linhas: não há o que encomendar.');
        }

        // Os totais das encomendas sao em euros; converter a olho era
        // inventar um valor.
        if ($data['currency'] !== null && $data['currency'] !== 'EUR') {
            $reason = "O recibo está em {$data['currency']}, não em euros: tem de ser registado à mão.";
            $this->alerts->etsyNeedsReview($data['receipt_id'], $reason);

            return $this->park($data, EtsyReceipt::STATUS_NEEDS_REVIEW, $reason);
        }

        // Fora da transacao, como todo o I/O externo. Se falhar, a encomenda
        // entra na mesma: o pagamento e informacao, nao condicao.
        $payment = $this->payment($connection->shop_id, $data['receipt_id']);

        [$lines, $unmatched] = $this->matchLines($data['lines']);
        $adjustment = $data['gift_wrap_cents'] - $data['discount_cents'];
        $status = $unmatched === [] ? EtsyReceipt::STATUS_IMPORTED : EtsyReceipt::STATUS_NEEDS_REVIEW;

        try {
            $order = $this->orders->createFromMarketplace([
                'sales_channel' => 'etsy',
                'payment_method' => 'etsy',
                'external_order_reference' => (string) $data['receipt_id'],
                'customer_name' => $data['customer_name'],
                'shipping_address' => $data['address'],
                'shipping_cents' => $data['shipping_cents'],
                'adjustment_cents' => $adjustment,
                'adjustment_reason' => $this->adjustmentReason($data),
                'stock_reason' => 'etsy_order',
                'note' => "Importada da Etsy (recibo {$data['receipt_id']}).",
                'lines' => $lines,
            ], function (Order $order) use ($data, $status, $payment, $unmatched): void {
                // Na mesma transacao da encomenda: ou nascem as duas, ou
                // nenhuma. E o unico do etsy_receipt_id que trava a segunda.
                EtsyReceipt::query()->create([
                    ...$this->mirror($data),
                    'order_id' => $order->id,
                    'status' => $status,
                    'totals' => [...$this->totals($data), ...$payment],
                    'unmatched_lines' => $unmatched === [] ? null : $unmatched,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Outro processo importou-a no mesmo instante: a transacao desta
            // desfez-se toda, encomenda e stock incluidos.
            return null;
        }

        // Ja marcada como enviada la quando a vimos pela primeira vez.
        if ($data['is_shipped']) {
            $this->shipHere($order, $data);
        }

        if ($unmatched !== []) {
            $this->alerts->etsyNeedsReview(
                $data['receipt_id'],
                'Há linhas que não casaram com nenhuma variante do 12studio ('.implode(', ', array_map(
                    fn (array $line): string => $line['sku'] ?? $line['title'],
                    $unmatched,
                )).'). Entraram como linhas livres, para produção; o stock não foi mexido por elas.',
                $order,
            );

            return 'review';
        }

        return 'imported';
    }

    /**
     * Um recibo que ja ca esta: acompanha o que aconteceu la.
     *
     * @param  MappedReceipt  $data
     * @return 'updated'|null
     */
    private function follow(EtsyReceipt $row, array $data): ?string
    {
        $order = $row->order;

        // Ficou por rever sem chegar a ser encomenda: e com uma pessoa.
        if ($order === null) {
            return null;
        }

        if ($row->etsy_updated_at !== null && $data['updated_at'] !== null && $data['updated_at']->lte($row->etsy_updated_at)) {
            return null;
        }

        $status = $row->status;

        if ($data['is_canceled'] && ! in_array($order->status, ['cancelled', 'refunded'], true)) {
            if (in_array($order->status, ['shipped', 'delivered'], true)) {
                // A peca ja saiu: o stock nao volta sozinho, e so quem sabe
                // se ela regressa e que pode decidir.
                $status = EtsyReceipt::STATUS_NEEDS_REVIEW;
                $this->alerts->etsyNeedsReview($row->etsy_receipt_id, 'Foi cancelada na Etsy depois de já ter sido enviada. A encomenda ficou como estava e o stock não foi reposto.', $order);
            } else {
                // Repoe o stock que tinha saido da prateleira.
                $this->orders->transitionOrder($order, 'cancelled', null, 'Cancelada na Etsy.');
            }
        } elseif ($data['refund'] === 'full' && $order->payment_status !== 'refunded') {
            $this->orders->setPaymentStatus($order, 'refunded', null, 'Reembolsada na Etsy.');
        } elseif ($data['refund'] === 'partial' && $order->payment_status === 'paid') {
            $this->orders->setPaymentStatus($order, 'partially_refunded', null, 'Reembolso parcial na Etsy.');
        }

        $shippedOnEtsy = $row->shipped_on_etsy_at;

        // Marcada como enviada la (a mao, no gestor da loja): espelha-se ca,
        // e fica registado para a fase do envio nao a empurrar de volta.
        if ($data['is_shipped'] && $shippedOnEtsy === null) {
            $shippedOnEtsy = now();
            // Gravado ANTES de a passar a enviada ca: e por isto que o gancho
            // do "enviada" sabe que nao tem de a mandar de volta para la.
            $row->update(['shipped_on_etsy_at' => $shippedOnEtsy]);
            $this->shipHere($order->refresh(), $data);
        }

        $row->update([
            'status' => $status,
            'etsy_status' => $data['etsy_status'],
            'etsy_updated_at' => $data['updated_at'],
            'shipped_on_etsy_at' => $shippedOnEtsy,
        ]);

        return 'updated';
    }

    /**
     * A encomenda passa a enviada ca, com o seguimento que a Etsy tiver —
     * sem email nosso (nao ha email) e sem empurrar nada de volta.
     *
     * @param  MappedReceipt  $data
     */
    private function shipHere(Order $order, array $data): void
    {
        if (! $order->isPaid() || ! in_array($order->status, ['paid', 'in_production', 'ready_to_ship'], true)) {
            return;
        }

        $order->update([
            'tracking_number' => $order->tracking_number ?? $data['shipment']['tracking_code'] ?? null,
            'shipping_method_name' => $order->shipping_method_name ?? $data['shipment']['carrier'] ?? null,
        ]);

        $this->orders->transitionOrder($order, 'shipped', null, 'Marcada como enviada na Etsy.');
    }

    /**
     * Um recibo que nao deu encomenda fica registado na mesma, para nao ser
     * tentado de 5 em 5 minutos e para o dono o ver.
     *
     * @param  MappedReceipt  $data
     * @return 'review'|'failed'
     */
    private function park(array $data, string $status, string $reason): string
    {
        EtsyReceipt::query()->create([
            ...$this->mirror($data),
            'status' => $status,
            'totals' => $this->totals($data),
            'last_error' => Str::limit($reason, 500, ''),
        ]);

        return $status === EtsyReceipt::STATUS_FAILED ? 'failed' : 'review';
    }

    /**
     * O que do recibo se guarda ca tal e qual.
     *
     * @param  MappedReceipt  $data
     * @return array<string, mixed>
     */
    private function mirror(array $data): array
    {
        return [
            'etsy_receipt_id' => $data['receipt_id'],
            'etsy_status' => $data['etsy_status'],
            'buyer_message' => $data['buyer_message'],
            'gift_message' => $data['gift_message'],
            'etsy_created_at' => $data['created_at'],
            'etsy_updated_at' => $data['updated_at'],
            'shipped_on_etsy_at' => $data['is_shipped'] ? now() : null,
        ];
    }

    /**
     * @param  MappedReceipt  $data
     * @return array<string, int|string|null>
     */
    private function totals(array $data): array
    {
        return [
            'currency' => $data['currency'],
            'grandtotal_cents' => $data['grandtotal_cents'],
            'shipping_cents' => $data['shipping_cents'],
            'discount_cents' => $data['discount_cents'],
            'gift_wrap_cents' => $data['gift_wrap_cents'],
            // O imposto que a Etsy cobra ao comprador e entrega ela: nao
            // entra nos nossos totais, mas fica dito.
            'tax_cents' => $data['tax_cents'],
            'vat_cents' => $data['vat_cents'],
        ];
    }

    /**
     * O pagamento como a Etsy o reporta: bruto, a taxa de processamento, e o
     * liquido. Vazio se nao der para ler.
     *
     * @return array<string, int>
     */
    private function payment(int $shopId, int $receiptId): array
    {
        try {
            $payment = EtsyPresenter::rows($this->api->receiptPayments($shopId, $receiptId)['results'] ?? null)[0] ?? null;
        } catch (EtsyException) {
            return [];
        }

        if ($payment === null) {
            return [];
        }

        return array_filter([
            'gross_cents' => EtsyPresenter::cents($payment['amount_gross'] ?? null),
            'fees_cents' => EtsyPresenter::cents($payment['amount_fees'] ?? null),
            'net_cents' => EtsyPresenter::cents($payment['amount_net'] ?? null),
        ], fn (?int $cents): bool => $cents !== null);
    }

    /**
     * Cada linha do recibo com a variante nossa que lhe corresponde: pela
     * referencia e, faltando, pelo numero que a Etsy deu a variacao no
     * ultimo envio do inventario.
     *
     * @param  array<int, MappedLine>  $lines
     * @return array{0: array<int, array{variant: Variant|null, product_name: string, sku: string|null, qty: int, unit_price_cents: int, personalization: array<string, string>|null}>, 1: array<int, array{sku: string|null, title: string, quantity: int, listing_id: int|null, product_id: int|null}>}
     */
    private function matchLines(array $lines): array
    {
        $matched = [];
        $unmatched = [];

        foreach ($lines as $line) {
            $variant = null;

            if ($line['sku'] !== null) {
                $variant = Variant::query()->whereRaw('lower(sku) = ?', [Str::lower($line['sku'])])->with('product')->first();
            }

            if ($variant === null && $line['product_id'] !== null) {
                $variant = Variant::query()
                    ->whereIn('id', EtsyVariant::query()->where('etsy_product_id', $line['product_id'])->select('variant_id'))
                    ->with('product')
                    ->first();
            }

            if ($variant === null) {
                $unmatched[] = [
                    'sku' => $line['sku'],
                    'title' => $line['title'],
                    'quantity' => $line['quantity'],
                    'listing_id' => $line['listing_id'],
                    'product_id' => $line['product_id'],
                ];
            }

            $matched[] = [
                'variant' => $variant,
                'product_name' => $line['title'],
                'sku' => $line['sku'],
                'qty' => $line['quantity'],
                'unit_price_cents' => $line['unit_price_cents'],
                'personalization' => $line['choices'] === [] ? null : $line['choices'],
            ];
        }

        return [$matched, $unmatched];
    }

    /**
     * @param  MappedReceipt  $data
     */
    private function adjustmentReason(array $data): ?string
    {
        $parts = array_filter([
            $data['discount_cents'] > 0 ? 'desconto de '.$this->euros($data['discount_cents']) : null,
            $data['gift_wrap_cents'] > 0 ? 'embrulho de '.$this->euros($data['gift_wrap_cents']) : null,
        ]);

        return $parts === [] ? null : 'Etsy: '.implode(', ', $parts).'.';
    }

    private function euros(int $cents): string
    {
        return str_replace('.', ',', Money::toDecimal($cents)).' €';
    }
}
