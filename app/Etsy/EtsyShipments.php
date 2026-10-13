<?php

namespace App\Etsy;

use App\Alerts\OrderAlerts;
use App\Models\EtsyConnection;
use App\Models\EtsyReceipt;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Str;

/**
 * O envio de uma encomenda da Etsy, dito a Etsy.
 *
 * Cada vez que isto acontece a Etsy manda um email ao comprador. Por isso:
 *
 *   - vai UMA vez, quando a encomenda passa a enviada (PushEtsyShipment);
 *   - o que a Etsy ja mostra como enviado (marcado la, a mao) nao se manda;
 *   - o seguimento acrescentado depois so vai com alguem a pedir (`resend`);
 *   - o que falhar nao se repete sozinho: fica escrito no recibo e avisa.
 *
 * O 12studio nunca avisa ele o comprador — nem tem o email dele.
 */
class EtsyShipments
{
    public function __construct(
        private EtsyApi $api,
        private EtsyTokens $tokens,
        private EtsyShipping $shipping,
        private OrderService $orders,
        private OrderAlerts $alerts,
    ) {}

    /**
     * Diz a Etsy que a encomenda foi enviada, com o seguimento que ela
     * tiver. Sem `resend`, nao faz nada se a Etsy ja o sabe.
     *
     * Lanca EtsyException com o que a Etsy disse.
     *
     * @param  array{resend?: bool, note_to_buyer?: string|null}  $options
     */
    public function push(Order $order, array $options = []): EtsyReceipt
    {
        $receipt = $this->receiptOf($order);
        $resend = (bool) ($options['resend'] ?? false);

        if (! $resend && ($receipt->tracking_pushed_at !== null || $receipt->shipped_on_etsy_at !== null)) {
            return $receipt;
        }

        $shopId = $this->connection()->shop_id;

        try {
            $response = $this->api->createReceiptShipment($shopId, $receipt->etsy_receipt_id, $this->shipment($order, $options));
        } catch (EtsyException $exception) {
            $receipt->update(['tracking_push_error' => Str::limit($exception->getMessage(), 500, '')]);

            throw $exception;
        }

        $receipt->update([
            'tracking_pushed_at' => now(),
            'tracking_push_error' => null,
            'shipped_on_etsy_at' => $receipt->shipped_on_etsy_at ?? now(),
            'etsy_status' => is_string($response['status'] ?? null) ? Str::limit(Str::lower($response['status']), 30, '') : $receipt->etsy_status,
        ]);

        return $receipt;
    }

    /**
     * O mesmo, para a fila: nunca lanca. O que a Etsy recusar fica no recibo
     * e avisa o dono para a marcar como enviada na Etsy — ca a encomenda ja
     * seguiu, e bem: a peca saiu pela porta.
     */
    public function pushQuietly(Order $order): void
    {
        try {
            $this->push($order);
        } catch (EtsyException $exception) {
            $receipt = $order->etsyReceipt;

            if ($receipt !== null) {
                $this->alerts->etsyNeedsReview(
                    $receipt->etsy_receipt_id,
                    "O envio não chegou à Etsy: {$exception->getMessage()} A encomenda seguiu no 12studio; marca-a como enviada na Etsy (ou volta a tentar na página da encomenda), senão o comprador não é avisado.",
                    $order,
                );
            }
        }
    }

    /**
     * Enviar de uma vez, a pedido: grava o seguimento, diz a Etsy e passa a
     * encomenda a enviada.
     *
     * Primeiro a Etsy, so depois a transicao ca: assim o gancho do "enviada"
     * ja encontra o envio feito e nao o manda segunda vez. Se a Etsy recusar
     * (sem permissao, estado que nao deixa), a encomenda segue ca na mesma e
     * a recusa vem no recibo; se a Etsy nem responder, nao se mexe em nada
     * e volta-se a tentar.
     *
     * @param  array{tracking_number?: string|null, tracking_url?: string|null, carrier?: string|null, note_to_buyer?: string|null, resend?: bool}  $options
     */
    public function ship(Order $order, array $options, ?User $by): Order
    {
        $receipt = $this->receiptOf($order);

        $this->orders->setTracking($order, array_filter([
            'tracking_number' => $options['tracking_number'] ?? null,
            'tracking_url' => $options['tracking_url'] ?? null,
            'shipping_method_name' => $options['carrier'] ?? null,
        ], fn (?string $value): bool => $value !== null && $value !== ''));

        try {
            $this->push($order->refresh(), [
                'resend' => (bool) ($options['resend'] ?? false),
                'note_to_buyer' => $options['note_to_buyer'] ?? null,
            ]);
        } catch (EtsyApiException) {
            // A Etsy respondeu, e respondeu que nao: ja ficou no recibo.
        } catch (EtsyException $exception) {
            // A Etsy nem respondeu, e ca nao se mexeu em nada: esta tentativa
            // nao conta. Se ficasse como "ja tentado", o aviso automatico
            // calava-se quando a encomenda fosse enviada mais tarde.
            // Pela base de dados, e nao por este modelo: quem gravou o erro
            // foi outra instancia, e para esta "nada mudou".
            EtsyReceipt::query()->whereKey($receipt->getKey())->update(['tracking_push_error' => null]);

            throw $exception;
        }

        if (in_array($order->status, ['paid', 'in_production', 'ready_to_ship'], true)) {
            // Uma encomenda da Etsy com um reembolso parcial (um desconto dado
            // depois) ja nao conta como "paga" e o pipeline travava-a — com a
            // Etsy ja avisada do envio. Vai forcada, com a razao escrita.
            $order = $order->isPaid()
                ? $this->orders->transitionOrder($order, 'shipped', $by, 'Enviada.')
                : $this->orders->transitionOrder($order, 'shipped', $by, 'Enviada. O pagamento na Etsy não está por inteiro (reembolso parcial).', force: true);
        }

        return $order->setRelation('etsyReceipt', $receipt->refresh());
    }

    /**
     * Marca um recibo como pago e/ou enviado, sem seguimento
     * (updateShopReceipt). Para o caso raro de o corrigir a mao.
     *
     * @return array<array-key, mixed>
     */
    public function markReceipt(int $receiptId, ?bool $paid = null, ?bool $shipped = null): array
    {
        $response = $this->api->updateReceipt($this->connection()->shop_id, $receiptId, array_filter([
            'was_paid' => $paid,
            'was_shipped' => $shipped,
        ], fn (?bool $value): bool => $value !== null));

        // A Etsy ja a mostra enviada: fica dito ca, para o "enviada" do
        // 12studio nao lhe mandar outro aviso (e outro email ao comprador).
        if ($shipped === true) {
            EtsyReceipt::query()
                ->where('etsy_receipt_id', $receiptId)
                ->whereNull('shipped_on_etsy_at')
                ->update(['shipped_on_etsy_at' => now()]);
        }

        return $response;
    }

    /**
     * O corpo do createReceiptShipment. Sem numero de seguimento nao vai
     * transportadora nem codigo: a Etsy so marca como enviada.
     *
     * @param  array{note_to_buyer?: string|null}  $options
     * @return array<string, string>
     */
    private function shipment(Order $order, array $options): array
    {
        $body = [];

        if (filled($order->tracking_number)) {
            $body['tracking_code'] = (string) $order->tracking_number;
            $body['carrier_name'] = $this->carrier($order->shipping_method_name);
        }

        if (filled($options['note_to_buyer'] ?? null)) {
            $body['note_to_buyer'] = Str::limit(trim((string) $options['note_to_buyer']), 500, '');
        }

        return $body;
    }

    /**
     * A transportadora como a Etsy a conhece. A que ela nao conhece vai como
     * `other`: aceita o codigo na mesma, so nao faz o seguimento sozinha.
     */
    private function carrier(?string $name): string
    {
        $wanted = Str::lower(trim((string) $name));

        if ($wanted === '') {
            return 'other';
        }

        try {
            foreach ($this->shipping->carriers() as $carrier) {
                if (is_string($carrier['name'] ?? null) && Str::lower($carrier['name']) === $wanted) {
                    return $carrier['name'];
                }
            }
        } catch (EtsyException) {
            // Sem a lista, vai como "other": o codigo segue na mesma.
        }

        return 'other';
    }

    private function receiptOf(Order $order): EtsyReceipt
    {
        $receipt = $order->isFromEtsy() ? $order->etsyReceipt()->first() : null;

        if ($receipt === null) {
            throw new EtsyException("A encomenda {$order->order_number} não veio da Etsy: não há envio para lhe dizer.");
        }

        return $receipt;
    }

    private function connection(): EtsyConnection
    {
        return $this->tokens->connection() ?? throw EtsyException::notConnected();
    }
}
