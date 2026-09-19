<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\Presenters\CatalogPresenter;
use App\Mcp\Tools\StudioTool;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('product_get')]
#[Title('Ver produto')]
#[Description('Ficha completa de um produto: descrição, categoria, etiquetas, imagens e todas as variantes com preço normal, promocional e de revenda, stock (físico, reservado e disponível) e dados de produção. Indica o produto pelo id ou pelo slug.')]
#[IsReadOnly]
#[IsIdempotent]
class ProductGetTool extends StudioTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id do produto.'),
            'slug' => $schema->string()->max(200)->description('Slug do produto (em alternativa ao id).'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'required_without:slug'],
            'slug' => ['nullable', 'string', 'max:200'],
        ]);

        $product = Product::query()
            ->with(['category', 'tags', 'images', 'variants.color', 'variants.material'])
            ->when(
                isset($data['id']),
                fn ($query) => $query->whereKey($data['id']),
                fn ($query) => $query->where('slug', $data['slug']),
            )
            ->first();

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        return Response::structured(CatalogPresenter::productDetail($product));
    }
}
