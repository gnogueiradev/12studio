<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyApi;
use App\Etsy\EtsyException;
use App\Etsy\EtsyListingSync;
use App\Etsy\EtsyPresenter;
use App\Etsy\EtsyTokens;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
use App\Mcp\Tools\StudioTool;
use App\Models\EtsyListing;
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

#[Name('etsy_listing_get')]
#[Title('Ver um anúncio da Etsy')]
#[Description('Um anúncio da Etsy por dentro: título, descrição, etiquetas, categoria, perfis, as fotografias, o vídeo e o inventário (cada variação com referência, preço e quantidade). Indica "listing_id" (o número do anúncio na Etsy — serve para qualquer anúncio, também de outras lojas) ou "product_id" (o produto do 12studio, para ver o anúncio a que está ligado). Mostra o que está LÁ; o que o 12studio enviaria vê-se com etsy_listing_preview.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyListingGetTool extends StudioTool
{
    use TalksToEtsy;

    public function __construct(
        private EtsyApi $api,
        private EtsyTokens $tokens,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'listing_id' => $schema->integer()->min(1)->description('Número do anúncio na Etsy.'),
            'product_id' => $schema->integer()->min(1)->description('Id do produto no 12studio (em alternativa a listing_id).'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'listing_id' => ['nullable', 'integer', 'min:1', 'required_without:product_id'],
            'product_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'listing_id.required_without' => 'Indica "listing_id" ou "product_id".',
        ]);

        $link = null;

        if (isset($data['listing_id'])) {
            $listingId = (int) $data['listing_id'];
            $link = EtsyListing::query()->where('etsy_listing_id', $listingId)->with('product')->first();
        } else {
            $link = EtsyListing::query()->where('product_id', (int) $data['product_id'])->with('product')->first();

            if ($link === null || $link->etsy_listing_id === null) {
                return Response::error('Este produto ainda não tem anúncio na Etsy. Se o anúncio já existe lá, liga-o com etsy_listing_link.');
            }

            $listingId = $link->etsy_listing_id;
        }

        $connection = $this->tokens->connection() ?? throw EtsyException::notConnected();
        $remote = $this->api->listing($listingId, EtsyListingSync::DETAIL_INCLUDES);

        // O texto de um anuncio de outra loja e de um estranho: vai como dados.
        $ours = (int) ($remote['shop_id'] ?? 0) === $connection->shop_id;

        return Response::structured([
            'is_our_shop' => $ours,
            'linked_product' => $link === null ? null : [
                'id' => $link->product->id,
                'name' => $link->product->name,
                'deleted' => $link->product->trashed(),
            ],
            'listing' => EtsyPresenter::listingDetail($remote, trusted: $ours),
        ]);
    }
}
