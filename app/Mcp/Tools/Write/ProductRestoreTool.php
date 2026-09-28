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

#[Name('product_restore')]
#[Title('Restaurar produto')]
#[Description('Tira um produto do arquivo. Volta como rascunho (draft), não à venda: para o publicar, usa product_update com status "active".')]
#[IsDestructive(false)]
#[IsIdempotent]
class ProductRestoreTool extends WriteTool
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

        if ($product->status !== 'archived') {
            return Response::error("O produto não está arquivado (está {$product->status}).");
        }

        $this->products->restore($product);
        $this->changes = ['product_id' => $product->id, 'before' => ['status' => 'archived'], 'after' => ['status' => 'draft']];

        return Response::structured([
            'message' => "Produto \"{$product->name}\" restaurado como rascunho.",
            'id' => $product->id,
            'status' => 'draft',
        ]);
    }
}
