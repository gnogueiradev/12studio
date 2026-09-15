<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminActionRequest;
use App\Http\Requests\Pricing\PricingPreviewRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Tag;
use App\Models\Variant;
use App\Services\PricingPreview;
use App\Services\PricingSettings;
use App\Services\ProductService;
use App\Services\TagService;
use App\Support\ColorOptions;
use App\Support\MaterialOptions;
use App\Support\Micros;
use App\Support\Money;
use App\Support\PrinterOptions;
use App\Support\VariantSku;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /** Os tamanhos de pagina que a listagem aceita, como strings do URL. */
    private const PAGE_SIZES = ['8', '20', '50'];

    public function __construct(
        private ProductService $productService,
        private TagService $tagService,
        private PricingPreview $preview,
        private PricingSettings $pricingSettings,
    ) {}

    /**
     * A listagem, e so a listagem.
     *
     * O formulario mudou-se para pagina propria (ver `create`/`edit`) e levou
     * com ele as cores, os materiais, as impressoras e o preco sugerido — que
     * eram carregados em TODOS os pedidos desta pagina so porque o modal podia
     * abrir.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        // `?editar={id}` foi o endereco do formulario durante toda a vida do
        // modal, e anda em historicos e em separadores guardados. Um id que ja
        // nao existe (ou lixo) cai para a listagem normal, em vez de dar 404: o
        // parametro vem do URL, e um URL partilhado sobrevive ao produto que o
        // originou.
        $legacy = $request->query('editar');

        if ($legacy !== null && ctype_digit((string) $legacy)) {
            $product = Product::query()->find((int) $legacy);

            if ($product !== null) {
                return to_route('admin.produtos.edit', $product);
            }
        }

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'category_id' => (string) $request->query('category_id', ''),
            'fulfillment_mode' => (string) $request->query('fulfillment_mode', ''),
            'tag' => (string) $request->query('tag', ''),
            // So os tamanhos do seletor "por pagina". Fora da lista cai para ''
            // (os 20 de sempre): o numero vem do URL e sem esta porta um
            // `?per_page=100000` carregava o catalogo inteiro num pedido.
            'per_page' => in_array((string) $request->query('per_page', ''), self::PAGE_SIZES, true)
                ? (string) $request->query('per_page')
                : '',
        ];

        // Base com todos os filtros MENOS o estado, como nas encomendas e nos
        // clientes: se as contagens respeitassem o proprio filtro de estado,
        // todas as chips excepto a activa mostrariam zero e deixavam de servir
        // para navegar.
        $scoped = fn () => Product::query()
            ->when($filters['category_id'] !== '', fn ($query) => $query->where('category_id', $filters['category_id']))
            ->when($filters['fulfillment_mode'] !== '', fn ($query) => $query->where('fulfillment_mode', $filters['fulfillment_mode']))
            // Dentro do $scoped e nao depois: filtrar por etiqueta tem de
            // reduzir as contagens das chips de estado, como a pesquisa faz.
            // A relacao ja restringe ao ambito, portanto o slug nao precisa de
            // desempate — "natal" de encomenda nunca chega aqui.
            ->when($filters['tag'] !== '', fn ($query) => $query->whereHas(
                'tags',
                fn ($tag) => $tag->where('slug', $filters['tag']),
            ))
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
            ->with(['category', 'primaryImage', 'defaultVariant', 'tags'])
            ->withCount('variants')
            // Pronto a sair hoje, somado em todas as variantes. A subtracao vai
            // dentro do SUM porque `available_stock` e um acessor calculado —
            // nao existe como coluna para o agregado somar.
            ->withSum('variants as ready_stock', DB::raw('stock - reserved_stock'))
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] === '' ? 20 : (int) $filters['per_page'])
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
                'tags' => $product->tags->pluck('name')->all(),
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
        ]);
    }

    /**
     * A pagina do formulario, em branco: o produto novo.
     *
     * Leva o `PricingPreviewRequest` como o `edit` — a matriz nao usa o painel
     * de custo, mas a prop tem de existir para a pagina ser a mesma nos dois
     * modos.
     */
    public function create(PricingPreviewRequest $request): Response
    {
        return Inertia::render('admin/produtos/form', [
            ...$this->formLists(null),
            'editing' => null,
            'pricing' => $this->preview->fromRequest($request),
        ]);
    }

    /**
     * A pagina do formulario com um produto dentro.
     *
     * O painel de custo da ficha de variante nao espelha a formula em
     * TypeScript: recarrega a prop `pricing` (`only: ['pricing']`) com os
     * campos do calculo no URL e deixa o servidor responder, como na
     * calculadora. E por isso que um `edit` recebe um `PricingPreviewRequest`.
     */
    public function edit(PricingPreviewRequest $request, Product $product): Response
    {
        $editing = $this->editingProduct($product);

        return Inertia::render('admin/produtos/form', [
            // Antes das listas de propósito: sao as variantes do produto aberto
            // que dizem que cores e materiais arquivados tem de continuar a
            // aparecer.
            ...$this->formLists($editing),
            'editing' => $editing,
            // Sem peso nem tempo no URL o `isCalculable()` diz que nao, e isto
            // sai a `result: null` — que e exatamente como o painel de custo
            // tem de abrir numa variante nova.
            'pricing' => $this->preview->fromRequest($request),
        ]);
    }

    /**
     * As listas que o formulario precisa, iguais a criar e a editar.
     *
     * @param  array<string, mixed>|null  $editing
     * @return array<string, mixed>
     */
    private function formLists(?array $editing): array
    {
        return [
            'categories' => $this->categoryOptions(),
            'colors' => ColorOptions::all(array_column($editing['variants'] ?? [], 'colorId')),
            'materials' => MaterialOptions::all(array_column($editing['variants'] ?? [], 'materialId')),
            'printers' => PrinterOptions::all($this->pricingSettings->electricityPriceMicrosPerKwh()),
            'defaultActiveLaborMinutes' => $this->pricingSettings->activeLaborMinutes(),
            // Do ambito `product` e so dele: desde que as etiquetas deixaram de
            // ser exclusivas do catalogo, sugerir todas era oferecer
            // "revendedor" e "urgente" ao classificar um vaso.
            'tagSuggestions' => $this->tagService->suggestions(Tag::SCOPE_PRODUCT),
            'defaultVatRate' => (int) config('shop.default_vat_rate', 23),
        ];
    }

    /**
     * O produto da pagina, com a galeria e as variantes.
     *
     * Nao se semeia da linha da listagem, ao contrario dos materiais e das
     * impressoras: a linha nao traz categoria, descricao, etiquetas nem IVA, e
     * alargar o `->through()` para os trazer era carregar vinte descricoes em
     * HTML, vinte galerias e vinte matrizes de variantes em cada render da
     * listagem para servir a que se abre.
     *
     * @return array<string, mixed>
     */
    private function editingProduct(Product $product): array
    {
        $product->load(['tags', 'images']);

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
            // A semente do campo SKU quando se cria uma variante nova na
            // gaveta. A mesma numeracao da matriz — ver VariantSku.
            'suggestedSku' => VariantSku::next($product),
            'updatedAt' => $product->updated_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * Criado o produto, fica-se na pagina dele.
     *
     * Criar e so o principio — faltam as fotografias, os tempos do slicer e os
     * precos de cada variante, e nenhuma dessas coisas cabe no pedido de
     * criacao. Voltar a listagem era fechar a porta na cara de quem ainda tem
     * trabalho para fazer ali dentro.
     */
    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = $this->productService->store($request->validated());

        $this->toast('Produto criado.');

        return to_route('admin.produtos.edit', $product);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->productService->update($product, $request->validated());

        $this->toast('Produto atualizado.');

        // Fica-se onde se estava: gravar o nome nao e razao para perder de
        // vista a galeria e as variantes que se estava a arrumar.
        return to_route('admin.produtos.edit', $product);
    }

    /**
     * "Apagar" no admin = arquivar (regra global de eliminacao logica).
     */
    public function destroy(AdminActionRequest $request, Product $product): RedirectResponse
    {
        $this->productService->archive($product);

        $this->toast('Produto arquivado.');

        return back();
    }

    /**
     * Desarquivar: volta a rascunho, nao a montra. Ver ProductService::restore.
     */
    public function restore(AdminActionRequest $request, Product $product): RedirectResponse
    {
        $this->productService->restore($product);

        $this->toast('Produto restaurado como rascunho.');

        return back();
    }

    /**
     * Variantes do produto para a seccao "Variantes" da pagina.
     *
     * Traz duas coisas ao mesmo tempo: o que a LINHA mostra (a cor e o material
     * com nome e tom, o preco efetivo, o stock disponivel) e o que o
     * FORMULARIO edita (os ids, os precos em euros, o tempo de impressao). Sao
     * poucas variantes por produto, e uma segunda viagem ao servidor so para
     * abrir a ficha de uma delas dava uma gaveta a piscar.
     *
     * A linha traz o formulario inteiro tambem por outra razao: o preco e o
     * stock editam-se na propria linha, e o endpoint da variante herda as
     * regras do `store` — um patch so com o preco era um pedido a que faltava
     * o SKU.
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
            ->map(function (Variant $variant): array {
                $suggested = $this->preview->forVariant($variant);

                return [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'sizeLabel' => $variant->size_label,
                    'colorId' => $variant->color_id,
                    'color' => $variant->color === null ? null : [
                        'id' => $variant->color->id,
                        'name' => $variant->color->name,
                        'hex' => $variant->color->hex_color,
                    ],
                    // Ao lado da cor e nao dentro dela: sao dois eixos, e o
                    // material chegava aqui atraves da cor so porque a cor lhe
                    // pertencia.
                    'materialId' => $variant->material_id,
                    'material' => $variant->material === null ? null : [
                        'id' => $variant->material->id,
                        'name' => $variant->material->name,
                    ],
                    'priceCents' => $variant->price_cents,
                    'compareAtCents' => $variant->compare_at_cents,
                    'wholesalePriceCents' => $variant->wholesale_price_cents,
                    // Desfaz a troca normal/promocional que o VariantService faz na
                    // escrita — o formulario nunca ve `price_cents` cru.
                    'normalPrice' => Money::toDecimal($variant->normalPriceCents()),
                    'salePrice' => $variant->salePriceCents() === null
                        ? null
                        : Money::toDecimal((int) $variant->salePriceCents()),
                    'wholesalePrice' => $variant->wholesale_price_cents === null
                        ? null
                        : Money::toDecimal($variant->wholesale_price_cents),
                    'filamentWeightGrams' => $variant->filament_weight_grams,
                    'printingTimeMinutes' => $variant->printing_time_minutes,
                    'printerProfileId' => $variant->printer_profile_id,
                    'packagingCost' => $variant->packaging_cost_cents === null
                        ? null
                        : Money::toDecimal($variant->packaging_cost_cents),
                    'componentsCost' => $variant->components_cost_cents === null
                        ? null
                        : Money::toDecimal($variant->components_cost_cents),
                    'activeLaborMinutes' => $variant->active_labor_minutes,
                    // O que a calculadora daria a esta variante tal como esta
                    // gravada, para a aba "Producao" mostrar ao lado do preco
                    // atual. Null quando falta peso, tempo ou material. O mesmo
                    // motor — e a mesma regra do material — que o "Aplicar
                    // precos" usa para escrever, portanto o que se ve e o que fica.
                    'suggestedRetailCents' => $suggested === null ? null : Micros::toCents($suggested->retailPriceMicros),
                    'suggestedWholesaleCents' => $suggested === null ? null : Micros::toCents($suggested->wholesalePriceMicros),
                    'stock' => $variant->stock,
                    'reservedStock' => $variant->reserved_stock,
                    'availableStock' => $variant->available_stock,
                    'lowStockThreshold' => $variant->low_stock_threshold,
                    'lowStock' => $variant->isLowStock(),
                    'isDefault' => $variant->is_default,
                    'active' => $variant->active,
                ];
            })
            ->all();
    }

    /**
     * Galeria do produto para a seccao "Fotografias" da pagina.
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
