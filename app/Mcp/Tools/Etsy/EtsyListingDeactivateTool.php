<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyListingLifecycle;
use App\Mcp\Tools\Etsy\Concerns\AsksBeforeEtsy;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
use App\Mcp\Tools\WriteTool;
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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('etsy_listing_deactivate')]
#[Title('Tirar um anúncio de venda na Etsy')]
#[Description('Desativa na Etsy o anúncio de um produto: deixa de estar à venda e de aparecer aos compradores, mas continua a existir (com as suas estatísticas) e pode voltar com etsy_listing_publish. Um anúncio desativado assim fica desativado: a sincronização não o volta a ligar. Pede sempre confirmação: a primeira chamada só avisa, e é preciso "confirm": true.')]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyListingDeactivateTool extends WriteTool
{
    use AsksBeforeEtsy;
    use TalksToEtsy;

    public function __construct(
        private EtsyListingLifecycle $lifecycle,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->min(1)->required()->description('Id do produto no 12studio.'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar o aviso da primeira resposta.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['product_id' => ['required', 'integer', 'min:1']]);
        $product = Product::query()->find((int) $data['product_id']);

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        $listing = EtsyListing::query()->where('product_id', $product->id)->first();

        if ($listing === null || $listing->etsy_listing_id === null) {
            return Response::error("\"{$product->name}\" não tem anúncio na Etsy.");
        }

        if ($listing->state !== 'active') {
            return Response::error("O anúncio {$listing->etsy_listing_id} não está à venda (estado: {$listing->state}). Não há o que desativar.");
        }

        if (! $request->boolean('confirm')) {
            return $this->askFirst("Desativar o anúncio {$listing->etsy_listing_id} de \"{$product->name}\" tira-o de venda na Etsy: os compradores deixam de o ver até ser publicado outra vez.");
        }

        $listing = $this->lifecycle->deactivate($product);

        $this->changes = [
            'product_id' => $product->id,
            'before' => ['state' => 'active'],
            'after' => ['state' => $listing->state],
        ];

        return Response::structured([
            'message' => "O anúncio {$listing->etsy_listing_id} de \"{$product->name}\" já não está à venda. Volta com etsy_listing_publish.",
            'etsy_listing_id' => $listing->etsy_listing_id,
            'state' => $listing->state,
        ]);
    }
}
