<?php

namespace App\Mcp\Tools\Write\Images;

use App\Mcp\Tools\Write\Concerns\HandlesProductImages;
use App\Mcp\Tools\WriteTool;
use App\Models\Product;
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

#[Name('product_images_reorder')]
#[Title('Reordenar fotografias')]
#[Description('Muda a ordem da galeria de um produto. Manda TODAS as fotografias do produto, pela nova ordem; se faltar alguma ou vier uma de outro produto, é recusado. Não muda qual é a principal (isso é o product_image_update).')]
#[IsDestructive(false)]
#[IsIdempotent]
class ProductImagesReorderTool extends WriteTool
{
    use HandlesProductImages;

    public function __construct(
        private ImageService $images,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required()->description('Id do produto.'),
            'image_ids' => $schema->array()->items($schema->integer())->required()->description('Os ids de todas as fotografias do produto, pela nova ordem.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'image_ids' => ['required', 'array'],
            'image_ids.*' => ['integer'],
        ]);

        $product = Product::withTrashed()->find((int) $data['product_id']);

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        if ($refusal = $this->refusalIfDeleted($product)) {
            return $refusal;
        }

        $context = ['product_id' => $product->id];
        $ids = array_map(intval(...), array_values($data['image_ids']));
        $owned = $product->images()->pluck('id')->all();

        // O ImageService ignora ids estranhos, porque a galeria confia pouco no
        // browser mas nao lhe pede a lista toda. Aqui a lista e o contrato:
        // ignorar seria dizer "reordenado" sem o ter feito.
        $missing = array_diff($owned, $ids);
        $strangers = array_diff($ids, $owned);

        if ($missing !== [] || $strangers !== [] || count($ids) !== count(array_unique($ids))) {
            return $this->refuse('ids_invalidos', $this->explain($missing, $strangers), $context);
        }

        return $this->logged($context, function () use ($product, $ids, $owned, $context): ResponseFactory {
            $this->images->reorder($product, $ids);

            $this->logDone($context);

            $this->changes = ['product_id' => $product->id, 'before' => $owned, 'after' => $ids];

            return Response::structured([
                'message' => 'Galeria reordenada.',
                'images' => $this->gallery($product),
            ]);
        });
    }

    /**
     * @param  array<int, int>  $missing
     * @param  array<int, int>  $strangers
     */
    private function explain(array $missing, array $strangers): string
    {
        return 'Nada foi mudado. '.implode(' ', array_filter([
            $missing === [] ? null : 'Faltam as fotografias '.implode(', ', $missing).'.',
            $strangers === [] ? null : 'Estas não são deste produto: '.implode(', ', $strangers).'.',
            $missing === [] && $strangers === [] ? 'Há ids repetidos.' : null,
        ]));
    }
}
