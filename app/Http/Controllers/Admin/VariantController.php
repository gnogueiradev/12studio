<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\PricingPreviewRequest;
use App\Http\Requests\Variant\StoreVariantRequest;
use App\Http\Requests\Variant\UpdateVariantRequest;
use App\Models\PrinterProfile;
use App\Models\Product;
use App\Models\Variant;
use App\Services\PricingPreview;
use App\Services\VariantService;
use App\Support\ColorOptions;
use App\Support\MaterialOptions;
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
 * O formulario tem cor, material, gramagem, tempo de impressao, impressora e
 * custos extra. Cor e material sao eixos independentes, e e o MATERIAL que
 * alimenta o custo de filamento — a cor nao tem preco. A pre-visualizacao chega
 * na prop `pricing` e recarrega-se sozinha (`only: ['pricing']`), com o MESMO
 * motor que calcula o preco gravado.
 */
class VariantController extends Controller
{
    public function __construct(
        private VariantService $variantService,
        private PricingPreview $preview,
    ) {}

    public function create(Product $product, PricingPreviewRequest $request): Response
    {
        return Inertia::render('admin/variantes/create', [
            'product' => $this->productSummary($product),
            'suggestedSku' => VariantSku::next($product),
            'colors' => ColorOptions::all(),
            'materials' => MaterialOptions::all(),
            'printers' => $this->printerOptions(),
            'pricing' => $this->preview->fromRequest($request),
        ]);
    }

    public function store(StoreVariantRequest $request, Product $product): RedirectResponse
    {
        $this->variantService->store($product, $request->validated(), $request->user());

        $this->toast('Variante criada.');

        // O produto ja nao tem pagina de edicao: volta-se a listagem com
        // `?editar={id}`, que e o que reabre o modal na seccao das variantes.
        // Aqui nao serve um `back()` — vinha-se do formulario da variante.
        return to_route('admin.produtos.index', ['editar' => $product->id]);
    }

    public function edit(Variant $variant, PricingPreviewRequest $request): Response
    {
        $variant->load('product');

        $this->seedPreviewFromVariant($request, $variant);

        return Inertia::render('admin/variantes/edit', [
            'product' => $this->productSummary($variant->product),
            'colors' => ColorOptions::all($variant->color_id),
            'materials' => MaterialOptions::all($variant->material_id),
            'printers' => $this->printerOptions(),
            'pricing' => $this->preview->fromRequest($request),
            'variant' => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'colorId' => $variant->color_id,
                'materialId' => $variant->material_id,
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
                'printingTimeMinutes' => $variant->printing_time_minutes,
                'printerProfileId' => $variant->printer_profile_id,
                'extraCost' => $variant->extra_cost_cents === null
                    ? null
                    : Money::toDecimal($variant->extra_cost_cents),
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

        return to_route('admin.produtos.index', ['editar' => $variant->product_id]);
    }

    /**
     * "Apagar" = arquivar: a variante tem movimentos de stock e itens de
     * encomenda agarrados (regra global de eliminacao logica).
     */
    public function destroy(Variant $variant): RedirectResponse
    {
        $this->variantService->archive($variant);

        $this->toast('Variante arquivada.');

        // `back()` e nao a listagem crua: arquivar dispara-se de dentro do
        // modal, e voltar ao endereco de onde se veio guarda a pagina, os
        // filtros e a pesquisa que estavam por tras dele.
        return back();
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
     * Na PRIMEIRA abertura da ficha nao ha parametros no URL, e a
     * pre-visualizacao tem de mostrar o preco da variante como ela esta
     * gravada. Nos recarregamentos parciais que vem a seguir o formulario ja
     * manda os campos, e ai sao esses que mandam.
     *
     * Escrever no pedido depois da validacao e seguro aqui: estes valores vem
     * da base de dados, nao de quem fez o pedido.
     */
    private function seedPreviewFromVariant(PricingPreviewRequest $request, Variant $variant): void
    {
        if ($request->hasAny(['weight_grams', 'hours', 'minutes', 'material_id', 'printer_profile_id', 'extra_cost'])) {
            return;
        }

        $minutes = $variant->printing_time_minutes ?? 0;

        $request->merge([
            'weight_grams' => $variant->filament_weight_grams ?? 0,
            'hours' => intdiv($minutes, 60),
            'minutes' => $minutes % 60,
            'material_id' => $variant->material_id,
            'printer_profile_id' => $variant->printer_profile_id,
            'extra_cost' => Money::toDecimal($variant->extra_cost_cents ?? 0),
        ]);
    }

    /**
     * @return array<int, array{id: int, name: string, hourlyRateCents: int, isDefault: bool}>
     */
    private function printerOptions(): array
    {
        return PrinterProfile::query()
            ->where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (PrinterProfile $profile): array => [
                'id' => $profile->id,
                'name' => $profile->name,
                'hourlyRateCents' => $profile->hourly_rate_cents,
                'isDefault' => $profile->is_default,
            ])
            ->all();
    }
}
