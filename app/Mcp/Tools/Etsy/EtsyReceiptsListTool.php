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
use App\Models\EtsyReceipt;
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

#[Name('etsy_receipts_list')]
#[Title('Vendas na Etsy (recibos)')]
#[Description('As vendas da loja lidas diretamente da Etsy, da mais recente para a mais antiga, com filtros: pagas ou não ("was_paid"), enviadas ou não ("was_shipped"), entregues, canceladas, e por datas de criação (AAAA-MM-DD). Cada uma diz se já entrou no 12studio e com que número de encomenda — para trabalhar numa encomenda usa order_get com esse número. Do comprador só vem o nome curto e o país. Para as que já foram importadas, orders_list (sales_channel "etsy") é mais rápido e não gasta pedidos à Etsy.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyReceiptsListTool extends StudioTool
{
    use PagesEtsy;
    use TalksToEtsy;

    private const FLAGS = ['was_paid', 'was_shipped', 'was_delivered', 'was_canceled'];

    public function __construct(
        private EtsyApi $api,
        private EtsyTokens $tokens,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'was_paid' => $schema->boolean()->description('true: só as pagas; false: só as por pagar.'),
            'was_shipped' => $schema->boolean()->description('true: só as já enviadas; false: só as por enviar.'),
            'was_delivered' => $schema->boolean()->description('true: só as entregues.'),
            'was_canceled' => $schema->boolean()->description('true: só as canceladas; false: só as não canceladas.'),
            'min_created' => $schema->string()->description('Criadas desta data em diante (AAAA-MM-DD).'),
            'max_created' => $schema->string()->description('Criadas até esta data (AAAA-MM-DD).'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'was_paid' => ['nullable', 'boolean'],
            'was_shipped' => ['nullable', 'boolean'],
            'was_delivered' => ['nullable', 'boolean'],
            'was_canceled' => ['nullable', 'boolean'],
            'min_created' => ['nullable', 'date_format:Y-m-d'],
            'max_created' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:min_created'],
            ...$this->pageRules(),
        ]);

        $connection = $this->tokens->connection() ?? throw EtsyException::notConnected();
        $page = $this->pageOf($request);

        $query = [
            'limit' => $page['limit'],
            'offset' => $page['offset'],
            'sort_on' => 'created',
            'sort_order' => 'desc',
            'min_created' => isset($data['min_created']) ? Carbon::parse($data['min_created'])->startOfDay()->getTimestamp() : null,
            'max_created' => isset($data['max_created']) ? Carbon::parse($data['max_created'])->endOfDay()->getTimestamp() : null,
        ];

        foreach (self::FLAGS as $flag) {
            if (isset($data[$flag])) {
                $query[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $response = $this->api->receipts($connection->shop_id, $query);
        $receipts = EtsyPresenter::rows($response['results'] ?? null);

        $rows = EtsyReceipt::query()
            ->whereIn('etsy_receipt_id', array_filter(array_column($receipts, 'receipt_id'), 'is_int'))
            ->with('order')
            ->get()
            ->keyBy('etsy_receipt_id');

        return Response::structured([
            'receipts' => array_map(
                fn (array $receipt): array => EtsyReceiptPresenter::summary($receipt, $rows->get($receipt['receipt_id'] ?? 0)),
                $receipts,
            ),
            'pagination' => $this->pageMetaOf($page, (int) ($response['count'] ?? count($receipts))),
        ]);
    }
}
