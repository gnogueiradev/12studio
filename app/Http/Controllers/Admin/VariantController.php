<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Variant\StoreVariantRequest;
use App\Http\Requests\Variant\UpdateVariantRequest;
use App\Models\Product;
use App\Models\Variant;
use App\Services\VariantService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Variantes vivem sempre no contexto de um produto (rota shallow): criar
 * passa por /admin/produtos/{product}/variantes/create, editar por
 * /admin/variantes/{variant}/edit. A listagem e a seccao "Variantes" da
 * pagina de edicao do produto.
 *
 * V1 deliberadamente sem materiais/cores (Fase 2) nem campos de custo: o
 * que as encomendas precisam e SKU, preco e stock.
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
            'suggestedSku' => $this->suggestSku($product),
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
            'variant' => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'sizeLabel' => $variant->size_label,
                'price' => Money::toDecimal($variant->price_cents),
                'compareAtPrice' => $variant->compare_at_cents === null
                    ? null
                    : Money::toDecimal($variant->compare_at_cents),
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

    /**
     * Sugestao a partir do slug ("caixa-ambar" -> "CAIXA-AMBAR-3"). O admin
     * pode reescrever; a unicidade e garantida na validacao.
     */
    private function suggestSku(Product $product): string
    {
        $base = strtoupper(str($product->slug)->limit(20, '')->value());

        return $base.'-'.($product->variants()->count() + 1);
    }
}
