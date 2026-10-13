<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyListingSync;
use App\Etsy\EtsyPresenter;
use App\Http\Requests\Etsy\LinkEtsyListingRequest;
use App\Mcp\FormRequestRunner;
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

#[Name('etsy_listing_link')]
#[Title('Ligar anúncio da Etsy a um produto')]
#[Description('Diz ao 12studio que um anúncio que JÁ existe na Etsy é de um produto. Não muda nada na Etsy: traz para cá o título, a descrição, as etiquetas e a categoria que lá estão (para o primeiro envio não os apagar) e casa as variantes pela referência. Os preços da Etsy não são adotados. A resposta diz que referências casaram e quais ficaram só de um dos lados. Com "unlink": true desfaz a ligação (o anúncio fica na Etsy como está).')]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyListingLinkTool extends WriteTool
{
    use TalksToEtsy;

    public function __construct(
        private EtsyListingSync $sync,
        private FormRequestRunner $runner,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->min(1)->required()->description('Id do produto no 12studio.'),
            'listing_id' => $schema->integer()->min(1)->description('Número do anúncio na Etsy (ver etsy_listings_list).'),
            'unlink' => $schema->boolean()->description('true para desfazer a ligação deste produto, em vez de ligar.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        // Com os apagados: o anuncio de um produto apagado tem de poder ser
        // libertado. Ligar, esse, so a produtos que existem.
        $product = Product::withTrashed()->find((int) $request->get('product_id'));

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        if ($product->trashed() && ! $request->boolean('unlink')) {
            return Response::error('Este produto foi apagado. Recupera-o primeiro com product_restore.');
        }

        $before = EtsyListing::query()->where('product_id', $product->id)->value('etsy_listing_id');

        if ($request->boolean('unlink')) {
            $this->sync->unlink($product);
            $this->changes = [
                'product_id' => $product->id,
                'before' => ['etsy_listing_id' => $before],
                'after' => ['etsy_listing_id' => null],
            ];

            return Response::structured([
                'message' => $before === null
                    ? "\"{$product->name}\" não estava ligado a nenhum anúncio."
                    : "\"{$product->name}\" desligado do anúncio {$before}. O anúncio continua na Etsy tal como está.",
            ]);
        }

        $data = $this->runner->validate(LinkEtsyListingRequest::class, $request->all(), $user, ['product' => $product]);

        $remote = $this->sync->fetchOwn((int) $data['listing_id']);
        $listing = $this->sync->adopt($product, $remote);

        $this->changes = [
            'product_id' => $product->id,
            'before' => ['etsy_listing_id' => $before],
            'after' => ['etsy_listing_id' => $listing->etsy_listing_id],
        ];

        return Response::structured([
            'message' => "\"{$product->name}\" ligado ao anúncio {$listing->etsy_listing_id}. Nada mudou na Etsy.",
            'references' => $this->sync->skuReport($product, $remote),
            'listing' => EtsyPresenter::listingSummary($remote),
        ]);
    }
}
