<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyListingSync;
use App\Etsy\EtsyPresenter;
use App\Http\Requests\Etsy\LinkEtsyListingRequest;
use App\Mcp\FormRequestRunner;
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

#[Name('etsy_listing_link')]
#[Title('Ligar anúncio da Etsy a um produto')]
#[Description('Diz ao 12studio que um anúncio que JÁ existe na Etsy é de um produto. Não muda nada na Etsy: traz para cá o título, a descrição, as etiquetas e a categoria que lá estão (para o primeiro envio não os apagar) e casa as variantes pela referência. Os preços da Etsy não são adotados. A resposta diz que referências casaram e quais ficaram só de um dos lados. Com "unlink": true desfaz a ligação (o anúncio fica na Etsy como está). Desligar, ou trocar o produto para OUTRO anúncio, deita fora o que o 12studio guardou para o anúncio antigo (conteúdo próprio e preços Etsy): a primeira chamada só avisa, e é preciso "confirm": true.')]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyListingLinkTool extends WriteTool
{
    use AsksBeforeEtsy;
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
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar o aviso da primeira resposta.'),
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

        $existing = EtsyListing::query()->where('product_id', $product->id)->first();
        $before = $existing?->etsy_listing_id;

        // O que se perde: o titulo, a descricao e as etiquetas proprios, os
        // precos Etsy por variante, as fotografias marcadas para nao irem.
        $lost = 'o 12studio deita fora o que guardou para ele (título, descrição e etiquetas próprios, preços Etsy por variante, fotografias excluídas). O anúncio em si fica na Etsy como está.';

        if ($request->boolean('unlink')) {
            if ($existing !== null && ! $request->boolean('confirm')) {
                return $this->askFirst("Ao desligar \"{$product->name}\" do anúncio".($before === null ? '' : " {$before}").", {$lost}");
            }

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

        if ($before !== null && $before !== (int) $data['listing_id'] && ! $request->boolean('confirm')) {
            return $this->askFirst("\"{$product->name}\" já está ligado ao anúncio {$before}. Ao trocá-lo para o {$data['listing_id']}, {$lost}");
        }

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
