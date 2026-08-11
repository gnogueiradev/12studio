<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Tag;
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
            'tagSuggestions' => $this->tagSuggestions(),
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
        $product->load(['tags', 'images']);

        return Inertia::render('admin/produtos/edit', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'categoryId' => $product->category_id,
                'description' => $product->description,
                'tags' => $product->tags->pluck('name')->all(),
                'status' => $product->status,
                'featured' => $product->featured,
                'vatRate' => $product->vat_rate,
                'fulfillmentMode' => $product->fulfillment_mode,
                'productionTimeDays' => $product->production_time_days,
                'allowBackorder' => $product->allow_backorder,
                'maxOpenProductionQty' => $product->max_open_production_qty,
            ],
            'categories' => $this->categoryOptions(),
            'tagSuggestions' => $this->tagSuggestions(),
            'images' => $this->imageRows($product),
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
            ->with('color.material')
            ->orderByDesc('is_default')
            ->orderBy('sku')
            ->get()
            ->map(fn (Variant $variant): array => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'sizeLabel' => $variant->size_label,
                'color' => $variant->color === null ? null : [
                    'id' => $variant->color->id,
                    'name' => $variant->color->name,
                    'hex' => $variant->color->hex_color,
                    'material' => $variant->color->material->name,
                ],
                'priceCents' => $variant->price_cents,
                'compareAtCents' => $variant->compare_at_cents,
                'wholesalePriceCents' => $variant->wholesale_price_cents,
                'filamentWeightGrams' => $variant->filament_weight_grams,
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
     * Galeria do produto para a seccao "Fotografias" da pagina de edicao.
     * A relacao `images` ja vem ordenada por sort_order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function imageRows(Product $product): array
    {
        return $product->images
            ->map(fn (ProductImage $image): array => [
                'id' => $image->id,
                'url' => $image->url,
                'alt' => $image->alt,
                'isPrimary' => $image->is_primary,
            ])
            ->values()
            ->all();
    }

    /**
     * Todas as etiquetas ja usadas, para o campo sugerir em vez de deixar o
     * admin criar "natal" e "Natal" sem dar por isso.
     *
     * @return array<int, string>
     */
    private function tagSuggestions(): array
    {
        return Tag::query()->orderBy('name')->pluck('name')->all();
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
