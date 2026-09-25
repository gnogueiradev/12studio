<?php

namespace App\Mcp\Tools\Write\Catalog;

use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Tools\WriteTool;
use App\Models\Category;
use App\Models\User;
use App\Services\CategoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('category_update')]
#[Title('Editar categoria')]
#[Description('Altera uma categoria: nome (o endereço acompanha), descrição, estado (visible, hidden, archived), cor ou ordem. Manda só o que muda. Arquivar tira-a da loja mas os produtos ficam.')]
#[IsDestructive(false)]
#[IsIdempotent]
class CategoryUpdateTool extends WriteTool
{
    private const FIELDS = ['name', 'description', 'status', 'color', 'sort_order'];

    public function __construct(
        private FormRequestRunner $runner,
        private CategoryService $categories,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da categoria (ver categories_list).'),
            'name' => $schema->string()->max(120),
            'description' => $schema->string()->max(2000),
            'status' => $schema->string()->enum(Category::STATUSES),
            'color' => $schema->string()->description('#rrggbb'),
            'sort_order' => $schema->integer()->min(0)->max(65535),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $category = Category::query()->find((int) $request->get('id'));

        if ($category === null) {
            return Response::error('Categoria não encontrada.');
        }

        $sent = Arr::only($request->all(), self::FIELDS);

        if ($sent === []) {
            return Response::error('Não indicaste nenhum campo para mudar.');
        }

        $current = Arr::only($category->toArray(), self::FIELDS);
        $data = $this->runner->validate(UpdateCategoryRequest::class, [...$current, ...$sent], $user, ['category' => $category]);
        $data = Arr::only($data, array_keys($sent));

        $before = Arr::only($current, array_keys($data));
        $this->categories->update($category, $data);
        $this->changes = ['category_id' => $category->id, 'before' => $before, 'after' => Arr::only($category->refresh()->toArray(), array_keys($data))];

        return Response::structured([
            'message' => 'Categoria atualizada.',
            'category' => ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug, 'status' => $category->status],
        ]);
    }
}
