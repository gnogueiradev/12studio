<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyShipments;
use App\Mcp\Tools\Etsy\Concerns\AsksBeforeEtsy;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
use App\Mcp\Tools\Write\Orders\Concerns\FindsOrders;
use App\Mcp\Tools\WriteTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use RuntimeException;

#[Name('etsy_order_ship')]
#[Title('Enviar uma encomenda da Etsy')]
#[Description('Marca uma encomenda da Etsy como enviada, cá e na Etsy, de uma vez: grava a transportadora e o número de seguimento (se os deres), diz à Etsy e passa a encomenda a "shipped". A ETSY MANDA UM EMAIL AO COMPRADOR cada vez que isto acontece, e por isso pede sempre confirmação: a primeira chamada só diz o que vai acontecer, e é preciso "confirm": true. Só para encomendas do canal "etsy" (para as outras usa order_transition). Sem número de seguimento, a Etsy só a marca como enviada. Uma transportadora que a Etsy não conheça vai como "other" (as que conhece vêm em etsy_shipping_list). Se a encomenda já foi enviada e queres mandar um seguimento novo, junta "resend": true — o comprador é avisado outra vez. O 12studio nunca envia emails seus a compradores da Etsy.')]
#[IsDestructive(false)]
#[IsOpenWorld]
class EtsyOrderShipTool extends WriteTool
{
    use AsksBeforeEtsy;
    use FindsOrders;
    use TalksToEtsy;

    public function __construct(
        private EtsyShipments $shipments,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->orderLookupSchema($schema),
            'carrier' => $schema->string()->max(120)->description('Transportadora (ex. "CTT"). Ver etsy_shipping_list para as que a Etsy conhece.'),
            'tracking_number' => $schema->string()->max(100)->description('Número de seguimento.'),
            'tracking_url' => $schema->string()->max(255)->description('Link de seguimento (fica só no 12studio).'),
            'note_to_buyer' => $schema->string()->max(500)->description('Mensagem que a Etsy inclui no email ao comprador.'),
            'resend' => $schema->boolean()->description('true para voltar a avisar a Etsy de uma encomenda já enviada (seguimento novo).'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar o aviso da primeira resposta.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'carrier' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'tracking_url' => ['nullable', 'url', 'max:255'],
            'note_to_buyer' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $this->findOrder($request);

        if ($order === null) {
            return Response::error('Encomenda não encontrada (indica order_id ou order_number).');
        }

        if (! $order->isFromEtsy() || $order->etsyReceipt === null) {
            return Response::error("A encomenda {$order->order_number} não veio da Etsy. Para a enviar usa order_transition.");
        }

        $receipt = $order->etsyReceipt;
        $resend = $request->boolean('resend');
        $alreadyShipped = in_array($order->status, ['shipped', 'delivered'], true);
        $etsyKnows = $receipt->tracking_pushed_at !== null || $receipt->shipped_on_etsy_at !== null;

        if (in_array($order->status, ['cancelled', 'refunded', 'pending_payment'], true)) {
            return Response::error("A encomenda {$order->order_number} está em {$order->status}: não é para enviar.");
        }

        if ($alreadyShipped && $etsyKnows && ! $resend) {
            return Response::error("A encomenda {$order->order_number} já foi enviada e a Etsy já sabe. Para mandar um seguimento novo (o comprador é avisado outra vez), junta \"resend\": true.");
        }

        $tracking = $data['tracking_number'] ?? $order->tracking_number;

        if (! $request->boolean('confirm')) {
            return $this->askFirst(sprintf(
                '%s %s (recibo %d na Etsy): a Etsy manda um email ao comprador%s.',
                $alreadyShipped ? 'Voltar a avisar a Etsy do envio da encomenda' : 'Marcar como enviada, cá e na Etsy, a encomenda',
                $order->order_number,
                $receipt->etsy_receipt_id,
                filled($tracking) ? ", com o seguimento {$tracking}" : ', sem número de seguimento',
            ));
        }

        $before = $order->status;

        try {
            $order = $this->shipments->ship($order, [...$data, 'resend' => $resend], $user);
        } catch (RuntimeException $exception) {
            // As invariantes do OrderService (por pagar, estado final) — e,
            // por herdar dela, tudo o que a Etsy recusar sem resposta.
            return Response::error($exception->getMessage());
        }

        $receipt->refresh();
        $told = $receipt->tracking_pushed_at !== null && $receipt->tracking_push_error === null;

        $this->changes = [
            'order_id' => $order->id,
            'before' => ['status' => $before],
            'after' => ['status' => $order->status, 'told_etsy' => $told, 'tracking_number' => $order->tracking_number],
        ];

        return Response::structured([
            'message' => $told
                ? "Encomenda {$order->order_number} enviada. A Etsy foi avisada e manda o email ao comprador."
                : "Encomenda {$order->order_number} enviada no 12studio, mas a Etsy NÃO aceitou o envio: {$receipt->tracking_push_error} Marca-a como enviada na Etsy, senão o comprador não é avisado.",
            'order_number' => $order->order_number,
            'status' => $order->status,
            'tracking_number' => $order->tracking_number,
            'carrier' => $order->shipping_method_name,
            'told_etsy' => $told,
            'problem' => $receipt->tracking_push_error,
        ]);
    }
}
