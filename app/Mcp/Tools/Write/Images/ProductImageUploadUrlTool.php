<?php

namespace App\Mcp\Tools\Write\Images;

use App\Mcp\ProductImageUploads;
use App\Mcp\Tools\WriteTool;
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

#[Name('product_image_upload_url')]
#[Title('Link para carregar fotografia')]
#[Description('Dá um link de uso único (15 minutos) para carregar UMA fotografia num produto. A imagem NÃO vai nesta chamada: envia-se depois por HTTP, com multipart/form-data no campo "image" — por exemplo: curl -sS -F "image=@foto.jpg" "<upload_url>". JPG, PNG ou WEBP, até 5 MB. A primeira fotografia de um produto fica principal sozinha; "make_primary" põe esta como principal mesmo que já haja outras. Para várias fotografias, pede um link por cada.')]
#[IsDestructive(false)]
class ProductImageUploadUrlTool extends WriteTool
{
    public function __construct(
        private ProductImageUploads $uploads,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required()->description('Id do produto.'),
            'alt' => $schema->string()->max(160)->description('Texto alternativo (o que a fotografia mostra). Opcional, edita-se depois com product_image_update.'),
            'make_primary' => $schema->boolean()->description('Tornar esta a fotografia principal do produto.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        // Produtos apagados nao aparecem aqui (SoftDeletes).
        $product = Product::query()->find((int) $request->get('product_id'));

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        $alt = $request->get('alt');
        $alt = is_string($alt) && trim($alt) !== '' ? mb_substr(trim($alt), 0, 160) : null;

        $link = $this->uploads->issue($product, $user, $alt, $request->boolean('make_primary'));

        $this->changes = ['product_id' => $product->id, 'after' => ['upload_link_issued' => true]];

        return Response::structured([
            'message' => "Link criado para carregar uma fotografia em \"{$product->name}\". Serve uma vez e expira às {$link['expires_at']->format('H:i')}.",
            'product_id' => $product->id,
            'upload_url' => $link['url'],
            'method' => 'POST',
            'field' => 'image',
            'example' => 'curl -sS -F "image=@foto.jpg" "'.$link['url'].'"',
            'expires_at' => $link['expires_at']->toIso8601String(),
            'accepted' => 'JPG, PNG ou WEBP, até 5 MB',
        ]);
    }
}
