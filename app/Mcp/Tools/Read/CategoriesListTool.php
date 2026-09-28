<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\Tools\StudioTool;
use App\Models\Category;
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

#[Name('categories_list')]
#[Title('Listar categorias')]
#[Description('Todas as categorias de produto, com o estado (visible = no menu da loja, hidden = existe mas não aparece no menu, archived) e quantos produtos tem cada uma.')]
#[IsReadOnly]
#[IsIdempotent]
class CategoriesListTool extends StudioTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $categories = Category::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Response::structured([
            'categories' => $categories->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'status' => $category->status,
                'products' => (int) $category->getAttribute('products_count'),
            ])->all(),
        ]);
    }
}
