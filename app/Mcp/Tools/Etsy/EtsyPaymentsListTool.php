<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyApi;
use App\Etsy\EtsyException;
use App\Etsy\EtsyPresenter;
use App\Etsy\EtsyTokens;
use App\Mcp\Presenters\EtsyReceiptPresenter;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
use App\Mcp\Tools\StudioTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('etsy_payments_list')]
#[Title('Pagamentos da Etsy')]
#[Description('O pagamento de uma venda da Etsy ("receipt_id"), ou vários pelos seus números ("payment_ids"): o bruto, a taxa e o líquido, o estado e os reembolsos. ATENÇÃO: a taxa que vem aqui é só a de processamento do pagamento; a taxa de transação e a de publicação não estão no pagamento, estão no extrato. A Etsy não lista pagamentos por datas: para ver o que entrou e saiu num período usa etsy_ledger_list.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyPaymentsListTool extends StudioTool
{
    use TalksToEtsy;

    public function __construct(
        private EtsyApi $api,
        private EtsyTokens $tokens,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'receipt_id' => $schema->integer()->min(1)->description('O pagamento desta venda.'),
            'payment_ids' => $schema->array()->items($schema->integer())->description('Em alternativa: números de pagamentos (até 100).'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'receipt_id' => ['nullable', 'integer', 'min:1', 'required_without:payment_ids'],
            'payment_ids' => ['nullable', 'array', 'min:1', 'max:100'],
            'payment_ids.*' => ['integer', 'min:1'],
        ], [
            'receipt_id.required_without' => 'Indica "receipt_id" ou "payment_ids". Por datas, usa etsy_ledger_list.',
        ]);

        $shopId = ($this->tokens->connection() ?? throw EtsyException::notConnected())->shop_id;

        $response = isset($data['receipt_id'])
            ? $this->api->receiptPayments($shopId, (int) $data['receipt_id'])
            : $this->api->payments($shopId, array_map('intval', (array) $data['payment_ids']));

        return Response::structured([
            'payments' => array_map(EtsyReceiptPresenter::payment(...), EtsyPresenter::rows($response['results'] ?? null)),
            'note' => '"fees" é só a taxa de processamento do pagamento. As outras taxas da Etsy estão no extrato (etsy_ledger_list).',
        ]);
    }
}
