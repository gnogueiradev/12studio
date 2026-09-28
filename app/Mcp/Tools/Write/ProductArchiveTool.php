<?php

namespace App\Mcp\Tools\Write;

use App\Mcp\Tools\WriteTool;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('product_archive')]
#[Title('Arquivar produto')]
#[Description('Tira um produto da loja (arquiva). Não apaga nada: variantes, stock e histórico de encomendas ficam. Desfaz-se com product_restore.')]
#[IsDestructive]
#[IsIdempotent]
class ProductArchiveTool extends WriteTool
{
    public function __construct(
        private ProductService $products,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id do produto.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $product = Product::query()->find((int) $request->get('id'));

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        $before = $product->status;
        $this->products->archive($product);
        $this->changes = ['product_id' => $product->id, 'before' => ['status' => $before], 'after' => ['status' => 'archived']];

        return Response::structured([
            'message' => "Produto \"{$product->name}\" arquivado.",
            'id' => $product->id,
            'status' => 'archived',
        ]);
    }
}
