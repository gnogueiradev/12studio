<?php

namespace App\Mcp\Tools\Write\Images;

use App\Mcp\Tools\Write\Concerns\FindsProductImages;
use App\Mcp\Tools\WriteTool;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
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
#[Description('Apaga uma fotografia de um produto. Ao contrário de produtos e variantes, isto NÃO tem volta: o ficheiro é apagado. Se era a principal, a seguinte na galeria passa a principal. Pede sempre confirmação: a primeira chamada diz o que está em jogo, e só com "confirm": true é que apaga.')]
#[IsDestructive]
#[IsIdempotent]
class ProductImageDeleteTool extends WriteTool
{
    use FindsProductImages;

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
        $image = $this->findImage((int) $request->get('image_id'));

        if ($image instanceof Response) {
            return $image;
        }

        $product = $image->product;

        if (! $request->boolean('confirm')) {
            return Response::error($this->warning($image));
        }

        $wasPrimary = $image->is_primary;
        $context = ['product_id' => $product->id, 'image_id' => $image->id, 'user_id' => $user->id, 'was_primary' => $wasPrimary];

        Log::info('MCP image delete started', $context);

        $this->images->delete($image);

        $newPrimary = $wasPrimary
            ? ProductImage::query()->where('product_id', $product->id)->where('is_primary', true)->value('id')
            : null;

        Log::info('MCP image delete completed', [...$context, 'new_primary_image_id' => $newPrimary]);

        $this->changes = [
            'product_id' => $product->id,
            'before' => ['image_id' => $image->id, 'is_primary' => $wasPrimary],
            'after' => ['image_id' => null, 'primary_image_id' => $newPrimary],
        ];

        return Response::structured([
            'message' => "Fotografia apagada de \"{$product->name}\"."
                .match (true) {
                    ! $wasPrimary => '',
                    $newPrimary === null => ' Era a única: o produto ficou sem fotografias.',
                    default => " A fotografia {$newPrimary} passou a principal.",
                },
            'product_id' => $product->id,
            'deleted_image_id' => $image->id,
            'primary_image_id' => $newPrimary,
        ]);
    }

    private function warning(ProductImage $image): string
    {
        $remaining = ProductImage::query()->where('product_id', $image->product_id)->count() - 1;

        return sprintf(
            'Vais apagar a fotografia %d de "%s"%s. O ficheiro é apagado e não se recupera. %s'
            .'Nada foi gravado. Mostra isto ao utilizador e, só se ele confirmar, repete com "confirm": true.',
            $image->id,
            $image->product->name,
            $image->alt === null ? '' : " (\"{$image->alt}\")",
            match (true) {
                ! $image->is_primary => '',
                $remaining === 0 => 'É a única fotografia: o produto fica sem nenhuma. ',
                default => 'É a fotografia principal: a seguinte na galeria passa a principal. ',
            },
        );
    }
}
