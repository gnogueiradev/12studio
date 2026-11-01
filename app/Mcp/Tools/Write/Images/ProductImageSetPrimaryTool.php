<?php

namespace App\Mcp\Tools\Write\Images;

use App\Mcp\Presenters\CatalogPresenter;
use App\Mcp\Tools\Write\Concerns\FindsProductImages;
use App\Mcp\Tools\WriteTool;
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

#[Name('product_image_set_primary')]
#[Title('Tornar fotografia principal')]
#[Description('Escolhe a fotografia principal de um produto — a que aparece nas listagens, no carrinho, nos emails e nas pré-visualizações de links. A antiga principal continua na galeria.')]
#[IsDestructive(false)]
#[IsIdempotent]
class ProductImageSetPrimaryTool extends WriteTool
{
    use FindsProductImages;

    public function __construct(
        private ImageService $images,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'image_id' => $schema->integer()->required()->description('Id da fotografia (ver product_get).'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $image = $this->findImage((int) $request->get('image_id'));

        if ($image instanceof Response) {
            return $image;
        }

        $previous = ProductImage::query()
            ->where('product_id', $image->product_id)
            ->where('is_primary', true)
            ->value('id');

        if ($previous === $image->id) {
            return Response::structured([
                'message' => 'Esta já era a fotografia principal. Nada mudou.',
                'image' => CatalogPresenter::image($image),
            ]);
        }

        $this->images->setPrimary($image);
        $image->refresh();

        $this->changes = [
            'product_id' => $image->product_id,
            'before' => ['primary_image_id' => $previous],
            'after' => ['primary_image_id' => $image->id],
        ];

        return Response::structured([
            'message' => "Fotografia principal de \"{$image->product->name}\" alterada.",
            'image' => CatalogPresenter::image($image),
        ]);
    }
}
