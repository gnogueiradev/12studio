<?php

namespace App\Mcp\Tools\Write\Images;

use App\Mcp\Tools\Write\Concerns\HandlesProductImages;
use App\Mcp\Tools\WriteTool;
use App\Models\EtsyListingImage;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('product_image_delete')]
#[Title('Apagar fotografia')]
#[Description('Apaga uma fotografia de vez, com os ficheiros: ao contrário dos produtos, isto não se recupera. Se era a principal, a seguinte na galeria assume o lugar. Pede sempre confirmação: a primeira chamada só avisa, e só com "confirm": true é que apaga.')]
#[IsDestructive]
#[IsIdempotent]
class ProductImageDeleteTool extends WriteTool
{
    use HandlesProductImages;

    public function __construct(
        private ImageService $images,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'image_id' => $schema->integer()->required()->description('Id da fotografia (ver product_get).'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar o que a primeira resposta mostrou.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $image = ProductImage::query()->find((int) $request->get('image_id'));

        if ($image === null) {
            return Response::error('Fotografia não encontrada.');
        }

        $product = $this->ownerOf($image);

        if ($refusal = $this->refusalIfDeleted($product)) {
            return $refusal;
        }

        if (! $request->boolean('confirm')) {
            return Response::error($this->warning($image));
        }

        $context = ['product_id' => $product->id, 'image_id' => $image->id, 'variant_id' => $image->variant_id];

        return $this->logged($context, function () use ($product, $image, $context): ResponseFactory {
            $this->images->delete($image);

            // A galeria nao fica com um buraco nas posicoes.
            $this->images->reorder($product, $product->images()->pluck('id')->all());

            $this->logDone($context);

            $this->changes = ['product_id' => $product->id, 'image_id' => $image->id, 'before' => ['path' => $image->path], 'after' => null];

            return Response::structured([
                'message' => 'Fotografia apagada.',
                'images' => $this->gallery($product),
            ]);
        });
    }

    private function warning(ProductImage $image): string
    {
        $inEtsy = EtsyListingImage::query()
            ->where('product_image_id', $image->id)
            ->where('excluded', false)
            ->exists();

        return sprintf(
            'Vais apagar a fotografia %d de vez, com os ficheiros: não se recupera. %s%s'
            .'Nada foi apagado. Mostra isto ao utilizador e, só se ele confirmar, repete com "confirm": true.',
            $image->id,
            $image->is_primary ? 'É a principal: a seguinte na galeria assume o lugar. ' : '',
            $inEtsy ? 'Está ligada a um anúncio da Etsy, que só muda no próximo etsy_listing_push. ' : '',
        );
    }
}
