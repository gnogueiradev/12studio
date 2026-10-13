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
#[Description('Tira um produto do arquivo ou recupera um apagado (os ids dos apagados vêm de products_list com "only_deleted": true). Volta como rascunho (draft), não à venda: para o publicar, usa product_update com status "active".')]
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
        // Com os apagados: e a unica porta de regresso da reciclagem.
        $product = Product::withTrashed()->find((int) $request->get('id'));

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        $wasDeleted = $product->trashed();

        if (! $wasDeleted && $product->status !== 'archived') {
            return Response::error("O produto não está arquivado nem apagado (está {$product->status}).");
        }

        $this->products->restore($product);
        $this->changes = [
            'product_id' => $product->id,
            'before' => ['status' => 'archived', 'deleted' => $wasDeleted],
            'after' => ['status' => 'draft', 'deleted' => false],
        ];

        return Response::structured([
            'message' => $wasDeleted
                ? "Produto \"{$product->name}\" recuperado como rascunho."
                : "Produto \"{$product->name}\" restaurado como rascunho.",
            'id' => $product->id,
            'status' => 'draft',
        ]);
    }
}
