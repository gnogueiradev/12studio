<?php

namespace App\Mcp\Tools\Write\Images;

use App\Mcp\Presenters\CatalogPresenter;
use App\Mcp\Tools\WriteTool;
use App\Models\Product;
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

#[Name('product_image_reorder')]
#[Title('Reordenar fotografias')]
#[Description('Muda a ordem da galeria de um produto. Manda TODOS os ids das fotografias do produto, pela ordem nova (ver product_get). Não muda a principal — para isso usa product_image_set_primary.')]
#[IsDestructive(false)]
#[IsIdempotent]
class ProductImageReorderTool extends WriteTool
{
    public function __construct(
        private ImageService $images,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required()->description('Id do produto.'),
            'image_ids' => $schema->array()->items($schema->integer())->required()->description('Todos os ids das fotografias do produto, pela ordem que devem aparecer.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $product = Product::query()->find((int) $request->get('product_id'));

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        $sent = array_map('intval', array_values((array) $request->get('image_ids', [])));
        $current = $product->images()->pluck('id')->map(fn ($id): int => (int) $id)->all();

        // O ImageService ignora ids que nao sao do produto (vem do browser);
        // aqui e mais estrito: uma lista parcial deixava ordens repetidas e
        // uma galeria que ninguem pediu. Ou vem a galeria inteira, ou nada.
        $sortedSent = $sent;
        $sortedCurrent = $current;
        sort($sortedSent);
        sort($sortedCurrent);

        if ($sortedSent !== $sortedCurrent) {
            return Response::error(sprintf(
                'Manda todos os ids das fotografias deste produto, cada um uma vez. O produto tem: %s.',
                $current === [] ? 'nenhuma fotografia' : implode(', ', $current),
            ));
        }

        $this->images->reorder($product, $sent);

        $this->changes = ['product_id' => $product->id, 'before' => ['order' => $current], 'after' => ['order' => $sent]];

        return Response::structured([
            'message' => 'Galeria reordenada.',
            'images' => $product->images()->get()->map(fn (ProductImage $image): array => CatalogPresenter::image($image))->all(),
        ]);
    }
}
