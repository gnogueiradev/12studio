<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function __construct(
        private CategoryService $categoryService,
    ) {}

    public function index(): Response
    {
        $categories = Category::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'active' => $category->active,
                'sortOrder' => $category->sort_order,
                'productsCount' => $category->products_count,
            ]);

        return Inertia::render('admin/categorias/index', [
            'categories' => $categories,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/categorias/create');
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
                'active' => $category->active,
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
     */
    public function destroy(Category $category): RedirectResponse
    {
        $this->categoryService->archive($category);

        $this->toast('Categoria arquivada.');

        return to_route('admin.categorias.index');
    }
}
