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

#[Name('etsy_listing_delete')]
#[Title('Apagar um anúncio da Etsy')]
#[Description('Apaga da Etsy o anúncio de um produto. Na Etsy isto NÃO se recupera: perdem-se as estatísticas, os favoritos e o endereço do anúncio. O produto do 12studio não muda, e o conteúdo preparado cá (título, descrição, preços Etsy) fica guardado — um novo etsy_listing_push cria um rascunho novo a partir dele. Para só tirar de venda, usa etsy_listing_deactivate. Serve também o anúncio que ficou de um produto apagado. Pede sempre confirmação: a primeira chamada só avisa, e é preciso "confirm": true. Se a Etsy não deixar apagar naquele estado, o anúncio é desativado e a resposta diz.')]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyListingDeleteTool extends WriteTool
{
    use AsksBeforeEtsy;
    use TalksToEtsy;

    public function __construct(
        private EtsyListingLifecycle $lifecycle,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->min(1)->required()->description('Id do produto no 12studio (também serve um produto apagado).'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar o aviso da primeira resposta.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['product_id' => ['required', 'integer', 'min:1']]);

        // Com os apagados: o anuncio de um produto apagado tem de poder sair.
        $product = Product::withTrashed()->find((int) $data['product_id']);

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        $listing = EtsyListing::query()->where('product_id', $product->id)->first();
        $before = $listing?->etsy_listing_id;

        if ($listing === null || $before === null) {
            return Response::error("\"{$product->name}\" não tem anúncio na Etsy: não há o que apagar.");
        }

        if (! $request->boolean('confirm')) {
            return $this->askFirst(sprintf(
                'Apagar o anúncio %d de "%s"%s: na Etsy não se recupera (vão-se as estatísticas, os favoritos e o endereço). O produto e o conteúdo preparado no 12studio ficam.',
                $before,
                $product->name,
                $listing->state === 'active' ? ', que está À VENDA' : '',
            ));
        }

        $this->lifecycle->delete($product);

        $this->changes = [
            'product_id' => $product->id,
            'before' => ['etsy_listing_id' => $before],
            'after' => ['etsy_listing_id' => null],
        ];

        return Response::structured([
            'message' => "O anúncio {$before} de \"{$product->name}\" foi apagado da Etsy. O conteúdo preparado cá ficou: etsy_listing_push cria um rascunho novo.",
            'deleted_listing_id' => $before,
        ]);
    }
}
