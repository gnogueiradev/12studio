<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Variant;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService,
    ) {}

    public function index(): Response
    {
        $products = Product::query()
            ->with('category')
            ->withCount('variants')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'status' => $product->status,
                'featured' => $product->featured,
                'fulfillmentMode' => $product->fulfillment_mode,
                'category' => $product->category?->name,
                'variantsCount' => $product->variants_count,
            ]);

        return Inertia::render('admin/produtos/index', [
            'products' => $products,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/produtos/create', [
            'categories' => $this->categoryOptions(),
            'defaultVatRate' => (int) config('shop.default_vat_rate', 23),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $this->productService->store($request->validated());

        $this->toast('Produto criado.');

        return to_route('admin.produtos.index');
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('admin/produtos/edit', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'categoryId' => $product->category_id,
                'description' => $product->description,
                'status' => $product->status,
                'featured' => $product->featured,
                'vatRate' => $product->vat_rate,
                'fulfillmentMode' => $product->fulfillment_mode,
                'productionTimeDays' => $product->production_time_days,
                'allowBackorder' => $product->allow_backorder,
                'maxOpenProductionQty' => $product->max_open_production_qty,
            ],
            'categories' => $this->categoryOptions(),
            'variants' => $this->variantRows($product),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->productService->update($product, $request->validated());

        $this->toast('Produto atualizado.');

        return to_route('admin.produtos.index');
    }

    /**
     * "Apagar" no admin = arquivar (regra global de eliminacao logica).
     */
    public function destroy(Product $product): RedirectResponse
    {
        $this->productService->archive($product);

        $this->toast('Produto arquivado.');

        return to_route('admin.produtos.index');
    }

    /**
     * Variantes do produto para a seccao "Variantes" da pagina de edicao.
     *
     * @return array<int, array<string, mixed>>
     */
    private function variantRows(Product $product): array
    {
        return $product->variants()
            ->orderByDesc('is_default')
            ->orderBy('sku')
            ->get()
            ->map(fn (Variant $variant): array => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'sizeLabel' => $variant->size_label,
                'priceCents' => $variant->price_cents,
                'compareAtCents' => $variant->compare_at_cents,
                'stock' => $variant->stock,
                'reservedStock' => $variant->reserved_stock,
                'availableStock' => $variant->available_stock,
                'lowStock' => $variant->isLowStock(),
                'isDefault' => $variant->is_default,
                'active' => $variant->active,
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function categoryOptions(): array
    {
        return Category::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ])
            ->all();
    }
}
