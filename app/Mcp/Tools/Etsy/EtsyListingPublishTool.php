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

#[Name('etsy_listing_publish')]
#[Title('Pôr um anúncio à venda na Etsy')]
#[Description('Publica na Etsy o anúncio de um produto: passa de rascunho (ou inativo) a ativo, e os compradores passam a vê-lo. CUSTA DINHEIRO: a Etsy cobra a taxa de publicação por cada anúncio publicado, e renovar um anúncio esgotado ou expirado também se paga. Por isso pede sempre confirmação: a primeira chamada diz o que vai acontecer, e só com "confirm": true é que publica. Só publica o que já foi enviado e conferido: com alterações por enviar, sem fotografia na Etsy ou com alguma coisa em falta, recusa e diz o quê (envia primeiro com etsy_listing_push).')]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyListingPublishTool extends WriteTool
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

        // Primeiro o que impede: nao se pede confirmacao para uma coisa que
        // nao pode ir.
        $blocker = $this->lifecycle->publishBlocker($product);

        if ($blocker !== null) {
            return Response::error($blocker.' Nada foi alterado na Etsy.');
        }

        $listing = EtsyListing::query()->where('product_id', $product->id)->firstOrFail();
        $before = $listing->state;

        if ($before === 'active') {
            return Response::structured([
                'message' => "O anúncio {$listing->etsy_listing_id} de \"{$product->name}\" já está à venda. Nada mudou.",
                'state' => 'active',
            ]);
        }

        if (! $request->boolean('confirm')) {
            return $this->askFirst(sprintf(
                'Publicar o anúncio %d de "%s" põe-no à venda na Etsy, e a Etsy cobra a taxa de publicação%s.',
                $listing->etsy_listing_id,
                $product->name,
                match ($before) {
                    'sold_out' => ' (é uma renovação: o anúncio estava esgotado)',
                    'expired' => ' (é uma renovação: o anúncio estava expirado)',
                    default => '',
                },
            ));
        }

        $listing = $this->lifecycle->publish($product);

        $this->changes = [
            'product_id' => $product->id,
            'before' => ['state' => $before],
            'after' => ['state' => $listing->state],
        ];

        return Response::structured([
            'message' => "O anúncio {$listing->etsy_listing_id} de \"{$product->name}\" está à venda na Etsy. A partir de agora o stock e os preços seguem para lá sozinhos.",
            'etsy_listing_id' => $listing->etsy_listing_id,
            'state' => $listing->state,
            'url' => $listing->url,
        ]);
    }
}
