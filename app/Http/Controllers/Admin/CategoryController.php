<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function __construct(
        private CategoryService $categoryService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
        ];

        // Base com a pesquisa mas SEM o filtro de estado, como nos produtos e
        // nas encomendas: se as contagens respeitassem o proprio filtro de
        // estado, todas as chips excepto a activa mostrariam zero e deixavam de
        // servir para navegar.
        $scoped = fn () => Category::query()
            ->when($filters['search'] !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$filters['search']}%")
                // O slug tambem entra na pesquisa: e o que se copia do URL da
                // loja quando se quer chegar depressa a uma categoria.
                ->orWhere('slug', 'like', "%{$filters['search']}%")));

        $statusCounts = $scoped()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        // Sem paginacao, ao contrario dos produtos: as categorias sao planas e
        // poucas (docs/plano.md), e uma lista que cabe toda no ecra nao ganha
        // nada com paginas.
        $categories = $scoped()
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'status' => $category->status,
                'color' => $category->color,
                'sortOrder' => $category->sort_order,
                'productsCount' => $category->products_count,
            ]);

        return Inertia::render('admin/categorias/index', [
            'categories' => $categories,
            'filters' => $filters,
            'statusCounts' => $statusCounts,
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $this->categoryService->store($request->validated());

        $this->toast('Categoria criada.');

        return to_route('admin.categorias.index');
    }

    public function edit(Category $category): Response
    {
        return Inertia::render('admin/categorias/edit', [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'status' => $category->status,
                'color' => $category->color,
                'sortOrder' => $category->sort_order,
            ],
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $this->categoryService->update($category, $request->validated());

        $this->toast('Categoria atualizada.');

        return to_route('admin.categorias.index');
    }

    /**
     * "Apagar" no admin = arquivar (regra global de eliminacao logica).
     *
     * Volta com `back()` e nao para o index limpo: arquiva-se de dentro de uma
     * vista filtrada, e mandar o utilizador para a lista sem filtros tirava-o
     * de onde estava a trabalhar.
     */
    public function destroy(Category $category): RedirectResponse
    {
        $this->categoryService->archive($category);

        $this->toast('Categoria arquivada.');

        return back();
    }

    public function restore(Category $category): RedirectResponse
    {
        $this->categoryService->restore($category);

        $this->toast('Categoria restaurada.');

        return back();
    }
}
