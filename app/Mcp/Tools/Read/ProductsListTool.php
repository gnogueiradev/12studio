<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\Presenters\CatalogPresenter;
use App\Mcp\Tools\Concerns\Paginates;
use App\Mcp\Tools\StudioTool;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('products_list')]
#[Title('Listar produtos')]
#[Description('Lista os produtos da loja, com preço mínimo/máximo e stock disponível. Filtra por estado (draft, active, archived), categoria, etiqueta ou texto no nome/SKU. Por omissão esconde os arquivados. Para ver variantes e preços de cada uma, usa product_get.')]
#[IsReadOnly]
#[IsIdempotent]
class ProductsListTool extends StudioTool
{
    use Paginates;

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->max(100)->description('Texto a procurar no nome do produto ou no SKU de uma variante.'),
            'status' => $schema->string()->enum(Product::STATUSES)->description('Só produtos neste estado. Sem isto, mostra rascunhos e ativos.'),
            'category_id' => $schema->integer()->description('Só produtos desta categoria (ver categories_list).'),
            'tag' => $schema->string()->max(100)->description('Só produtos com esta etiqueta (nome ou slug).'),
            ...$this->paginationSchema($schema),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', Product::STATUSES)],
            'category_id' => ['nullable', 'integer'],
            'tag' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::PER_PAGE_MAX],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $tag = trim((string) ($filters['tag'] ?? ''));

        $query = Product::query()
            ->with(['category', 'variants'])
            ->when(
                isset($filters['status']),
                fn (Builder $query) => $query->where('status', $filters['status']),
                fn (Builder $query) => $query->where('status', '!=', 'archived'),
            )
            ->when(isset($filters['category_id']), fn (Builder $query) => $query->where('category_id', $filters['category_id']))
            ->when($tag !== '', fn (Builder $query) => $query->whereHas(
                'tags',
                fn (Builder $inner) => $inner->where('slug', $tag)->orWhere('name', $tag),
            ))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('variants', fn (Builder $variant) => $variant->where('sku', 'like', "%{$search}%"))))
            ->orderByDesc('updated_at');

        $page = $this->paginate($query, $request);

        return Response::structured([
            'products' => collect($page->items())
                ->map(fn (Product $product): array => CatalogPresenter::productSummary($product))
                ->all(),
            'pagination' => $this->pageMeta($page),
        ]);
    }
}
