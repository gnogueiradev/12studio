<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyInsights;
use App\Mcp\McpFormat;
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

#[Name('etsy_market_shops')]
#[Title('Procurar lojas na Etsy')]
#[Description('Procura lojas da Etsy pelo nome e devolve, de cada uma, quantos anúncios tem, quantas vendas fez e a avaliação média. Serve para olhar para a concorrência. A nossa loja vê-se com etsy_status.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyMarketShopsTool extends StudioTool
{
    use PagesEtsy;
    use TalksToEtsy;

    public function __construct(
        private EtsyInsights $insights,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'shop_name' => $schema->string()->max(80)->required()->description('Nome (ou começo do nome) da loja.'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'shop_name' => ['required', 'string', 'max:80'],
            ...$this->pageRules(),
        ]);

        $page = $this->pageOf($request);
        $found = $this->insights->findShops((string) $data['shop_name'], $page['limit'], $page['offset']);

        return Response::structured([
            'shops' => array_map(fn (array $shop): array => [
                'shop_id' => $shop['shop_id'] ?? null,
                'shop_name' => $shop['shop_name'] ?? null,
                // Texto livre de outro vendedor.
                'title' => McpFormat::untrusted(is_string($shop['title'] ?? null) ? $shop['title'] : null),
                'url' => $shop['url'] ?? null,
                'currency_code' => $shop['currency_code'] ?? null,
                'listing_active_count' => $shop['listing_active_count'] ?? null,
                'transaction_sold_count' => $shop['transaction_sold_count'] ?? null,
                'review_average' => $shop['review_average'] ?? null,
                'review_count' => $shop['review_count'] ?? null,
                'num_favorers' => $shop['num_favorers'] ?? null,
                'shipping_from_country_iso' => $shop['shipping_from_country_iso'] ?? null,
            ], $found['results']),
            'pagination' => $this->pageMetaOf($page, $found['count']),
        ]);
    }
}
