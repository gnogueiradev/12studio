<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyListingSync;
use App\Etsy\EtsyPresenter;
use App\Mcp\Tools\Etsy\Concerns\PagesEtsy;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
use App\Mcp\Tools\StudioTool;
use App\Models\EtsyListing;
use App\Models\Product;
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

#[Name('etsy_listings_list')]
#[Title('Anúncios da loja na Etsy')]
#[Description('Lista os anúncios que a loja tem na Etsy num estado (active por omissão; draft, inactive, sold_out, expired). Cada um vem com o produto do 12studio a que está ligado ("linked_product") ou, não estando, com o palpite de qual será ("suggested_product", por referência ou por nome — é só uma sugestão). Para ligar, usa etsy_listing_link; para ver um anúncio por dentro, etsy_listing_get.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyListingsListTool extends StudioTool
{
    use PagesEtsy;
    use TalksToEtsy;

    public function __construct(
        private EtsyListingSync $sync,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'state' => $schema->string()->enum(EtsyListing::STATES)->description('Estado dos anúncios na Etsy. Sem isto, os ativos.'),
            ...$this->pageSchema($schema),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $filters = $request->validate([
            'state' => ['nullable', 'in:'.implode(',', EtsyListing::STATES)],
            ...$this->pageRules(),
        ]);

        $state = (string) ($filters['state'] ?? 'active');
        $page = $this->pageOf($request);
        $remote = $this->sync->remote($state, $page['limit'], $page['offset']);

        return Response::structured([
            'state' => $state,
            'listings' => array_map(fn (array $row): array => [
                ...EtsyPresenter::listingSummary($row['listing']),
                'linked_product' => $this->reference($row['linked']),
                'suggested_product' => $this->reference($row['suggested']),
            ], $remote['rows']),
            'pagination' => $this->pageMetaOf($page, $remote['count']),
        ]);
    }

    /**
     * @return array{id: int, name: string, deleted: bool}|null
     */
    private function reference(?Product $product): ?array
    {
        return $product === null
            ? null
            : ['id' => $product->id, 'name' => $product->name, 'deleted' => $product->trashed()];
    }
}
