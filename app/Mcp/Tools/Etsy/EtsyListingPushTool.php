<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyListingSync;
use App\Mcp\McpFormat;
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

#[Name('etsy_listing_push')]
#[Title('Enviar o anúncio de um produto para a Etsy')]
#[Description('Cria ou atualiza na Etsy o anúncio de um produto do 12studio: o anúncio (um RASCUNHO, se for novo — não fica à venda nem custa nada), o inventário (cada variação com referência, preço e quantidade), os atributos, as fotografias da galeria e o vídeo. O que a Etsy tiver de diferente é substituído pelo que o 12studio manda — vê primeiro com etsy_listing_preview ("compare": true). Não publica: um rascunho continua rascunho. Pede "confirm": true quando o anúncio já está à venda (os compradores veem a mudança na hora), quando está esgotado ou expirado (mexer-lhe pode renová-lo, e paga-se) e no primeiro envio para um anúncio que já existia na Etsy (o que lá foi posto à mão é substituído). Se faltar alguma coisa, não envia nada e diz o quê.')]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyListingPushTool extends WriteTool
{
    use AsksBeforeEtsy;
    use TalksToEtsy;

    public function __construct(
        private EtsyListingSync $sync,
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

        $payload = $this->sync->payload($product);

        if (! $payload->isReady()) {
            return Response::error('Ainda não dá para enviar este anúncio. '.implode(' ', $payload->blockers).' Nada foi enviado para a Etsy.');
        }

        $listing = EtsyListing::query()->where('product_id', $product->id)->first();
        $before = $listing?->etsy_listing_id;

        if ($before !== null && ! $request->boolean('confirm')) {
            if (in_array($listing->state, ['sold_out', 'expired'], true)) {
                return $this->askFirst(sprintf(
                    'O anúncio %d de "%s" está %s na Etsy: mexer-lhe pode renová-lo, e renovar paga a taxa de publicação.',
                    $before,
                    $product->name,
                    $listing->state === 'sold_out' ? 'esgotado' : 'expirado',
                ));
            }

            if ($listing->state === 'active') {
                return $this->askFirst("O anúncio {$before} de \"{$product->name}\" está à venda na Etsy: enviar muda já o que os compradores veem (título, preços, quantidades, fotografias). O que mudaria vê-se com etsy_listing_preview e \"compare\": true.");
            }

            // Um anuncio que ja la estava, feito a mao, e para o qual nunca
            // se enviou nada daqui: o primeiro envio passa por cima dele.
            if ($listing->last_pushed_at === null) {
                return $this->askFirst("O anúncio {$before} já existia na Etsy e este é o primeiro envio do 12studio para ele: os preços, as quantidades e as fotografias que lá estão são substituídos pelos do produto. O que mudaria vê-se com etsy_listing_preview e \"compare\": true.");
            }
        }

        $listing = $this->sync->push($product);

        $this->changes = [
            'product_id' => $product->id,
            'before' => ['etsy_listing_id' => $before],
            'after' => ['etsy_listing_id' => $listing->etsy_listing_id, 'state' => $listing->state],
        ];

        $state = $listing->state === 'draft' ? 'rascunho' : (string) $listing->state;
        $pending = $this->sync->pendingUploads($listing);

        return Response::structured([
            'message' => $before === null
                ? "Rascunho criado na Etsy: anúncio {$listing->etsy_listing_id}. Ainda NÃO está à venda — um rascunho só passa a estar depois de publicado."
                : "Anúncio {$listing->etsy_listing_id} atualizado na Etsy (estado: {$state}).",
            'etsy_listing_id' => $listing->etsy_listing_id,
            'state' => $listing->state,
            'url' => $listing->url,
            'variations_sent' => count($payload->variants),
            'images_on_etsy' => $listing->images()->whereNotNull('etsy_image_id')->count(),
            'video_on_etsy' => $listing->etsy_video_id !== null,
            // As fotografias e o video sobem a seguir, um ficheiro de cada vez.
            'still_uploading' => $pending,
            'still_uploading_note' => $pending['images'] > 0 || $pending['video']
                ? 'Ainda há ficheiros a subir (ou que a Etsy recusou). Confirma daqui a um minuto com etsy_listing_preview: "last_error" diz se algum falhou.'
                : null,
            // O anuncio foi; uma fotografia ou o video podem nao ter ido.
            'problems' => McpFormat::untrusted($listing->last_error),
            'warnings' => $payload->warnings,
        ]);
    }
}
