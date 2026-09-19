<?php

namespace App\Mcp\Tools\Write\Catalog;

use App\Http\Requests\Category\StoreCategoryRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Tools\WriteTool;
use App\Models\Category;
use App\Models\User;
use App\Services\CategoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('category_create')]
#[Title('Criar categoria')]
#[Description('Cria uma categoria de produtos. Por omissão fica oculta (hidden: existe mas não aparece no menu da loja); pede status "visible" para a mostrar.')]
#[IsDestructive(false)]
class CategoryCreateTool extends WriteTool
{
    public function __construct(
        private FormRequestRunner $runner,
        private CategoryService $categories,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(120)->required(),
            'description' => $schema->string()->max(2000),
            'status' => $schema->string()->enum(Category::STATUSES)->description('hidden (por omissão), visible ou archived.'),
            'color' => $schema->string()->description('Cor da bolinha no menu, #rrggbb.'),
            'sort_order' => $schema->integer()->min(0)->max(65535),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $this->runner->validate(StoreCategoryRequest::class, ['status' => 'hidden', ...$request->all()], $user);

        $category = $this->categories->store($data);
        $this->changes = ['created' => ['category_id' => $category->id]];

        return Response::structured([
            'message' => "Categoria \"{$category->name}\" criada ({$category->status}).",
            'category' => ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug, 'status' => $category->status],
        ]);
    }
}
