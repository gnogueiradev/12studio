<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Variant\StoreVariantRequest;
use App\Http\Requests\Variant\UpdateVariantRequest;
use App\Models\Color;
use App\Models\Product;
use App\Models\Variant;
use App\Services\VariantService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
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
            'suggestedSku' => $this->suggestSku($product),
            'colorGroups' => $this->colorGroups(),
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
            'colorGroups' => $this->colorGroups($variant->color_id),
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
     * Cores por material, para o seletor agrupado. Materiais e cores
     * arquivados ficam de fora — excepto o que a variante ja usa, senao o
     * seletor abria vazio e uma gravacao inocente perdia a cor.
     *
     * @return array<int, array{material: string, colors: array<int, array{id: int, name: string, hex: string}>}>
     */
    private function colorGroups(?int $keepColorId = null): array
    {
        return Color::query()
            ->with('material')
            ->where(function (Builder $query) use ($keepColorId): void {
                $query
                    ->where('is_active', true)
                    ->whereHas('material', fn (Builder $material) => $material->where('active', true));

                if ($keepColorId !== null) {
                    $query->orWhere('id', $keepColorId);
                }
            })
            ->get()
            // Ordenar e agrupar em memoria: sao dezenas de linhas, e ordenar
            // pelo sort_order do MATERIAL em SQL obrigava a um join so para
            // isto.
            ->sortBy([
                fn (Color $a, Color $b): int => $a->material->sort_order <=> $b->material->sort_order,
                fn (Color $a, Color $b): int => $a->material->name <=> $b->material->name,
                fn (Color $a, Color $b): int => $a->sort_order <=> $b->sort_order,
                fn (Color $a, Color $b): int => $a->name <=> $b->name,
            ])
            ->groupBy(fn (Color $color): string => $color->material->name)
            ->map(fn ($colors, string $material): array => [
                'material' => $material,
                'colors' => $colors
                    ->map(fn (Color $color): array => [
                        'id' => $color->id,
                        'name' => $color->name,
                        'hex' => $color->hex_color,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
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
