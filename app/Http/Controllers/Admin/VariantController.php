<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Variant\StoreVariantRequest;
use App\Http\Requests\Variant\UpdateVariantRequest;
use App\Models\Product;
use App\Models\Variant;
use App\Services\VariantService;
use App\Support\ColorGroups;
use App\Support\Money;
use App\Support\VariantSku;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Variantes vivem sempre no contexto de um produto (rota shallow): criar
 * passa por /admin/produtos/{product}/variantes/create, editar por
 * /admin/variantes/{variant}/edit. A listagem e a seccao "Variantes" da
 * pagina de edicao do produto.
 *
 * Ja tem cor e gramagem; os restantes campos de custo (tempo de impressao,
 * mao de obra, embalagem, taxa de falha) continuam a ser Fase 2 — chegam
 * com o CostService, que e quem lhes da uso.
 */
class VariantController extends Controller
{
    public function __construct(
        private VariantService $variantService,
    ) {}

    public function create(Product $product): Response
    {
        return Inertia::render('admin/variantes/create', [
            'product' => $this->productSummary($product),
            'suggestedSku' => VariantSku::next($product),
            'colorGroups' => ColorGroups::all(),
        ]);
    }

    public function store(StoreVariantRequest $request, Product $product): RedirectResponse
    {
        $this->variantService->store($product, $request->validated(), $request->user());

        $this->toast('Variante criada.');

        return to_route('admin.produtos.edit', $product);
    }

    public function edit(Variant $variant): Response
    {
        $variant->load('product');

        return Inertia::render('admin/variantes/edit', [
            'product' => $this->productSummary($variant->product),
            'colorGroups' => ColorGroups::all($variant->color_id),
            'variant' => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'colorId' => $variant->color_id,
                'sizeLabel' => $variant->size_label,
                // Desfaz a troca normal/promocional que o VariantService faz
                // na escrita — o formulario nunca ve `price_cents` cru.
                'normalPrice' => Money::toDecimal($variant->normalPriceCents()),
                'salePrice' => $variant->salePriceCents() === null
                    ? null
                    : Money::toDecimal((int) $variant->salePriceCents()),
                'wholesalePrice' => $variant->wholesale_price_cents === null
                    ? null
                    : Money::toDecimal($variant->wholesale_price_cents),
                'filamentWeightGrams' => $variant->filament_weight_grams,
                'stock' => $variant->stock,
                'reservedStock' => $variant->reserved_stock,
                'lowStockThreshold' => $variant->low_stock_threshold,
                'isDefault' => $variant->is_default,
                'active' => $variant->active,
            ],
        ]);
    }

    public function update(UpdateVariantRequest $request, Variant $variant): RedirectResponse
    {
        $this->variantService->update($variant, $request->validated(), $request->user());

        $this->toast('Variante atualizada.');

        return to_route('admin.produtos.edit', $variant->product_id);
    }

    /**
     * "Apagar" = arquivar: a variante tem movimentos de stock e itens de
     * encomenda agarrados (regra global de eliminacao logica).
     */
    public function destroy(Variant $variant): RedirectResponse
    {
        $this->variantService->archive($variant);

        $this->toast('Variante arquivada.');

        return to_route('admin.produtos.edit', $variant->product_id);
    }

    /**
     * @return array{id: int, name: string}
     */
    private function productSummary(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
        ];
    }
}
