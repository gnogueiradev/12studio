<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyApi;
use App\Etsy\EtsyException;
use App\Etsy\EtsyPresenter;
use App\Etsy\EtsyTokens;
use App\Mcp\Presenters\EtsyReceiptPresenter;
use App\Mcp\Tools\Etsy\Concerns\PagesEtsy;
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

#[Name('etsy_transactions_list')]
#[Title('Linhas vendidas na Etsy')]
#[Description('As linhas de venda da Etsy (uma por artigo comprado), lidas de lá. Indica UM de: "receipt_id" (as linhas de uma venda), "listing_id" (tudo o que um anúncio vendeu — serve para ver o que mais sai), "transaction_id" (uma linha), ou nada (as últimas da loja). Cada linha traz o anúncio, a referência, a quantidade, o preço e o que o comprador escolheu e escreveu (dentro de <dados_cliente>).')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyTransactionsListTool extends StudioTool
{
    use PagesEtsy;
    use TalksToEtsy;

    public function __construct(
        private EtsyApi $api,
        private EtsyTokens $tokens,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'receipt_id' => $schema->integer()->min(1)->description('As linhas desta venda.'),
            'listing_id' => $schema->integer()->min(1)->description('As vendas deste anúncio.'),
            'transaction_id' => $schema->integer()->min(1)->description('Uma linha, pelo seu número.'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'receipt_id' => ['nullable', 'integer', 'min:1', 'prohibits:listing_id,transaction_id'],
            'listing_id' => ['nullable', 'integer', 'min:1', 'prohibits:transaction_id'],
            'transaction_id' => ['nullable', 'integer', 'min:1'],
            ...$this->pageRules(),
        ], [
            'prohibits' => 'Indica só um de "receipt_id", "listing_id" ou "transaction_id".',
        ]);

        $shopId = ($this->tokens->connection() ?? throw EtsyException::notConnected())->shop_id;
        $page = $this->pageOf($request);
        $paging = ['limit' => $page['limit'], 'offset' => $page['offset']];

        if (isset($data['transaction_id'])) {
            return Response::structured([
                'transactions' => [EtsyReceiptPresenter::line($this->api->transaction($shopId, (int) $data['transaction_id']))],
            ]);
        }

        $response = match (true) {
            isset($data['receipt_id']) => $this->api->receiptTransactions($shopId, (int) $data['receipt_id']),
            isset($data['listing_id']) => $this->api->listingTransactions($shopId, (int) $data['listing_id'], $paging),
            default => $this->api->shopTransactions($shopId, $paging),
        };

        $transactions = EtsyPresenter::rows($response['results'] ?? null);

        return Response::structured([
            'transactions' => array_map(EtsyReceiptPresenter::line(...), $transactions),
            'pagination' => $this->pageMetaOf($page, (int) ($response['count'] ?? count($transactions))),
        ]);
    }
}
