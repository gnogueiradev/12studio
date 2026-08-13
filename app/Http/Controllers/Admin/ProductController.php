<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\PricingPreviewRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Tag;
use App\Models\Variant;
use App\Services\PricingPreview;
use App\Services\ProductService;
use App\Support\ColorOptions;
use App\Support\MaterialOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService,
        private PricingPreview $preview,
    ) {}

    /**
     * O `PricingPreviewRequest` a mais na assinatura serve o modal de novo
     * produto, que vive nesta pagina: ele pede o preco sugerido com um
     * recarregamento parcial (`only: ['pricingPreview']`) em vez de estimar a
     * margem no browser. Todas as regras dele sao `nullable`, por isso uma
     * listagem normal atravessa-o sem dar por ele.
     */
    public function index(Request $request, PricingPreviewRequest $pricing): Response
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'category_id' => (string) $request->query('category_id', ''),
            'fulfillment_mode' => (string) $request->query('fulfillment_mode', ''),
        ];

        // Base com todos os filtros MENOS o estado, como nas encomendas e nos
        // clientes: se as contagens respeitassem o proprio filtro de estado,
        // todas as chips excepto a activa mostrariam zero e deixavam de servir
        // para navegar.
        $scoped = fn () => Product::query()
            ->when($filters['category_id'] !== '', fn ($query) => $query->where('category_id', $filters['category_id']))
            ->when($filters['fulfillment_mode'] !== '', fn ($query) => $query->where('fulfillment_mode', $filters['fulfillment_mode']))
            // A referencia que o admin procura e o SKU da variante — o produto
            // nao tem nenhuma. Procurar so pelo nome deixava de fora a via mais
            // rapida de chegar a um produto: copiar a referencia de uma etiqueta.
            ->when($filters['search'] !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$filters['search']}%")
                ->orWhereHas('variants', fn ($variant) => $variant->where('sku', 'like', "%{$filters['search']}%"))));

        $statusCounts = $scoped()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $products = $scoped()
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            // A variante default e a que a montra usa para mostrar preco — e a
            // mesma de quem serve a referencia, a gramagem e o tempo na linha.
            ->with(['category', 'primaryImage', 'defaultVariant'])
            ->withCount('variants')
            // Pronto a sair hoje, somado em todas as variantes. A subtracao vai
            // dentro do SUM porque `available_stock` e um acessor calculado —
            // nao existe como coluna para o agregado somar.
            ->withSum('variants as ready_stock', DB::raw('stock - reserved_stock'))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'status' => $product->status,
                'featured' => $product->featured,
                'fulfillmentMode' => $product->fulfillment_mode,
                'productionTimeDays' => $product->production_time_days,
                'category' => $product->category?->name,
                'imageUrl' => $product->primaryImage?->url,
                'variantsCount' => $product->variants_count,
                'sku' => $product->defaultVariant?->sku,
                'priceCents' => $product->defaultVariant?->price_cents,
                'filamentWeightGrams' => $product->defaultVariant?->filament_weight_grams,
                'printingTimeMinutes' => $product->defaultVariant?->printing_time_minutes,
                'readyStock' => (int) $product->ready_stock,
            ]);

        return Inertia::render('admin/produtos/index', [
            'products' => $products,
            'filters' => $filters,
            'statusCounts' => $statusCounts,
            // Listas do modal de produto, que vive nesta pagina.
            'categories' => $this->categoryOptions(),
            'colors' => ColorOptions::all(),
            'materials' => MaterialOptions::all(),
            'tagSuggestions' => $this->tagSuggestions(),
            'defaultVatRate' => (int) config('shop.default_vat_rate', 23),
            'pricingPreview' => $this->preview->fromRequest($pricing),
            'editing' => $this->editingProduct($request),
        ]);
    }

    /**
     * O produto que o modal esta a editar, ou null quando esta a criar.
     *
     * Vem por `?editar={id}` e nao pela linha da listagem, ao contrario dos
     * materiais e das impressoras: a linha nao traz categoria, descricao,
     * etiquetas nem IVA, e alargar o `->through()` para os trazer era carregar
     * vinte descricoes em HTML, vinte galerias e vinte matrizes de variantes em
     * cada render da listagem para servir a que se abre.
     *
     * O modal pede-o com um recarregamento parcial (`only: ['editing']`), o
     * mesmo mecanismo com que ja pede o preco sugerido. O parametro fica no URL
     * de proposito: e o que faz o modal reabrir no produto certo depois de
     * qualquer accao da galeria ou das variantes, e o que torna
     * `/admin/produtos?editar=12` um endereco que se pode partilhar.
     *
     * @return array<string, mixed>|null
     */
    private function editingProduct(Request $request): ?array
    {
        $id = $request->query('editar');

        if ($id === null || ! ctype_digit((string) $id)) {
            return null;
        }

        $product = Product::query()->with(['tags', 'images'])->find((int) $id);

        if ($product === null) {
            return null;
        }

        return [
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
            'images' => $this->imageRows($product),
            'variants' => $this->variantRows($product),
        ];
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $this->productService->store($request->validated());

        $this->toast('Produto criado.');

        return to_route('admin.produtos.index');
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

        return back();
    }

    /**
     * Desarquivar: volta a rascunho, nao a montra. Ver ProductService::restore.
     */
    public function restore(Product $product): RedirectResponse
    {
        $this->productService->restore($product);

        $this->toast('Produto restaurado como rascunho.');

        return back();
    }

    /**
     * Variantes do produto para a seccao "Variantes" do modal de edicao.
     *
     * @return array<int, array<string, mixed>>
     */
    private function variantRows(Product $product): array
    {
        return $product->variants()
            ->with(['color', 'material'])
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
                ],
                // Ao lado da cor e nao dentro dela: sao dois eixos, e o
                // material chegava aqui atraves da cor so porque a cor lhe
                // pertencia.
                'material' => $variant->material === null ? null : [
                    'id' => $variant->material->id,
                    'name' => $variant->material->name,
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
     * Galeria do produto para a seccao "Fotografias" do modal de edicao.
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
        // Ocultas continuam a entrar: uma categoria oculta e uma categoria
        // viva que so nao se anuncia no menu, e ha produtos que lhe pertencem.
        // So a arquivada e que sai do seletor.
        return Category::query()
            ->where('status', '!=', 'archived')
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
