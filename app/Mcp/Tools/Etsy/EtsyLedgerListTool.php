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
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('etsy_ledger_list')]
#[Title('Extrato da conta de pagamentos da Etsy')]
#[Description('O extrato da conta de pagamentos da loja na Etsy entre duas datas ("from" e "to", AAAA-MM-DD, obrigatórias): cada movimento com o valor (positivo entra, negativo sai), o saldo a seguir, o tipo (venda, taxa de transação, taxa de publicação, processamento, reembolso, levantamento…) e a que se refere (por exemplo "receipt 3001"). É aqui que estão TODAS as taxas da Etsy — serve para saber quanto ela ficou de facto num período e acertar a estimativa de taxas em Etsy → Preços.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyLedgerListTool extends StudioTool
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
            'from' => $schema->string()->required()->description('Desde esta data (AAAA-MM-DD).'),
            'to' => $schema->string()->required()->description('Até esta data, inclusive (AAAA-MM-DD).'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            ...$this->pageRules(),
        ]);

        $shopId = ($this->tokens->connection() ?? throw EtsyException::notConnected())->shop_id;
        $page = $this->pageOf($request);

        $response = $this->api->ledgerEntries($shopId, [
            'min_created' => Carbon::parse($data['from'])->startOfDay()->getTimestamp(),
            'max_created' => Carbon::parse($data['to'])->endOfDay()->getTimestamp(),
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ]);

        $entries = EtsyPresenter::rows($response['results'] ?? null);

        return Response::structured([
            'entries' => array_map(EtsyReceiptPresenter::ledgerEntry(...), $entries),
            'pagination' => $this->pageMetaOf($page, (int) ($response['count'] ?? count($entries))),
        ]);
    }
}
