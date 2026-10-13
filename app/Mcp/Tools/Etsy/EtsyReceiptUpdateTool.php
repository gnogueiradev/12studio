<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyShipments;
use App\Mcp\Tools\Etsy\Concerns\AsksBeforeEtsy;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('etsy_receipt_update')]
#[Title('Marcar um recibo da Etsy como pago ou enviado')]
#[Description('Muda, na Etsy, as marcas de um recibo: "was_paid" (pago) e/ou "was_shipped" (enviado), para true ou false. Serve para corrigir à mão: um pagamento fora da Etsy, ou um envio marcado por engano. Para ENVIAR uma encomenda com seguimento usa etsy_order_ship, que trata de tudo. Não mexe na encomenda do 12studio na hora: a importação seguinte (até 5 minutos, ou etsy_orders_import) acompanha o que mudou. Pede sempre confirmação: a primeira chamada só avisa, e é preciso "confirm": true.')]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyReceiptUpdateTool extends WriteTool
{
    use AsksBeforeEtsy;
    use TalksToEtsy;

    public function __construct(
        private EtsyShipments $shipments,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'receipt_id' => $schema->integer()->min(1)->required()->description('Número do recibo na Etsy.'),
            'was_paid' => $schema->boolean()->description('true: marcar como pago; false: desmarcar.'),
            'was_shipped' => $schema->boolean()->description('true: marcar como enviado (sem seguimento); false: desmarcar.'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar o aviso da primeira resposta.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'receipt_id' => ['required', 'integer', 'min:1'],
            'was_paid' => ['nullable', 'boolean', 'required_without:was_shipped'],
            'was_shipped' => ['nullable', 'boolean'],
        ], [
            'was_paid.required_without' => 'Indica "was_paid" e/ou "was_shipped".',
        ]);

        $receiptId = (int) $data['receipt_id'];
        $paid = isset($data['was_paid']) ? filter_var($data['was_paid'], FILTER_VALIDATE_BOOLEAN) : null;
        $shipped = isset($data['was_shipped']) ? filter_var($data['was_shipped'], FILTER_VALIDATE_BOOLEAN) : null;

        $marks = implode(' e ', array_filter([
            $paid === null ? null : ($paid ? 'pago' : 'NÃO pago'),
            $shipped === null ? null : ($shipped ? 'enviado' : 'NÃO enviado'),
        ]));

        if (! $request->boolean('confirm')) {
            return $this->askFirst("Marcar o recibo {$receiptId} na Etsy como {$marks}: muda o que a Etsy e o comprador veem dessa venda.");
        }

        $receipt = $this->shipments->markReceipt($receiptId, $paid, $shipped);

        $this->changes = ['receipt_id' => $receiptId, 'was_paid' => $paid, 'was_shipped' => $shipped];

        return Response::structured([
            'message' => "Recibo {$receiptId} marcado na Etsy como {$marks}. A encomenda do 12studio acompanha na próxima importação.",
            'receipt_id' => $receiptId,
            'status' => $receipt['status'] ?? null,
            'is_paid' => $receipt['is_paid'] ?? null,
            'is_shipped' => $receipt['is_shipped'] ?? null,
        ]);
    }
}
