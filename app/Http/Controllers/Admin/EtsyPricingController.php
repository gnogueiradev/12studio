<?php

namespace App\Http\Controllers\Admin;

use App\Etsy\EtsyException;
use App\Etsy\EtsyInsights;
use App\Etsy\EtsyMargin;
use App\Etsy\EtsySettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Etsy\SaveEtsyPricingSettingsRequest;
use App\Models\Variant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Etsy -> Precos: cada variante com o que rende no site e o que renderia na
 * Etsy, lado a lado, e as escolhas que mexem nessa conta.
 *
 * E tudo conta nossa — nao se pede nada a Etsy para abrir esta pagina. As
 * taxas sao a estimativa que estiver nas definicoes, ate haver vendas.
 */
class EtsyPricingController extends Controller
{
    public function __construct(
        private EtsyMargin $margin,
        private EtsySettings $settings,
        private EtsyInsights $insights,
    ) {}

    public function index(Request $request): Response
    {
        $variants = Variant::query()
            ->where('active', true)
            // Os apagados ja ficam de fora (SoftDeletes); os arquivados nao
            // vao para a Etsy, e so faziam a lista comprida.
            ->whereHas('product', fn (Builder $query) => $query->where('status', '!=', 'archived'))
            ->with(['product', 'material', 'color', 'etsyVariant'])
            ->get()
            ->sortBy(fn (Variant $variant): array => [$variant->product->name, $variant->id])
            ->values();

        return Inertia::render('admin/etsy/precos', [
            'rows' => $variants->map(function (Variant $variant): array {
                $row = $this->margin->compare($variant);

                return [
                    'variantId' => $variant->id,
                    'productId' => $variant->product_id,
                    'product' => $variant->product->name,
                    'sku' => $variant->sku,
                    'label' => implode(' / ', array_filter([$variant->color?->name, $variant->material?->name, $variant->size_label])),
                    'priceSource' => $row['price_source'],
                    'site' => $this->side($row['site']),
                    'etsy' => $this->side($row['etsy']),
                ];
            })->all(),
            'settings' => [
                'markupPercent' => $this->percent($this->settings->markupBp()),
                'feePercent' => $this->percent($this->settings->feePercentageBp()),
                'feeFixed' => Money::toDecimal($this->settings->feeFixedCents()),
                'mtoQuantity' => $this->settings->mtoQuantity(),
                'stockBuffer' => $this->settings->stockBuffer(),
                'defaultTaxonomyId' => $this->settings->defaultTaxonomyId(),
                'whoMade' => $this->settings->whoMade(),
                'whenMade' => $this->settings->whenMade(),
            ],
            'options' => [
                'whoMade' => (array) config('etsy.listing.who_made'),
                'whenMade' => (array) config('etsy.listing.when_made'),
            ],
            // So quando o ecra procura uma categoria (recarga parcial).
            'taxonomyResults' => Inertia::optional(fn (): array => $this->searchTaxonomy((string) $request->query('categoria', ''))),
        ]);
    }

    public function update(SaveEtsyPricingSettingsRequest $request): RedirectResponse
    {
        $this->settings->save($request->settings());

        $this->toast('Guardado. Os preços e as margens já contam com isto; na Etsy só muda no próximo envio de cada anúncio.');

        return back();
    }

    /**
     * @param  array<string, int|null>  $side
     * @return array<string, int|null>
     */
    private function side(array $side): array
    {
        return [
            'priceCents' => $side['price_cents'],
            'exVatCents' => $side['ex_vat_cents'],
            'feesCents' => $side['fees_cents'],
            'netCents' => $side['net_cents'],
            'costCents' => $side['cost_cents'],
            'profitCents' => $side['profit_cents'],
            'marginBp' => $side['margin_bp'],
        ];
    }

    /**
     * Pontos base -> a percentagem como se escreve num campo ("12.5", "10").
     */
    private function percent(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, '.', ''), '0'), '.');
    }

    /**
     * @return array<int, array{id: int, name: string, path: string, level: int, is_leaf: bool}>
     */
    private function searchTaxonomy(string $query): array
    {
        try {
            return $this->insights->searchTaxonomy($query, 15);
        } catch (EtsyException) {
            return [];
        }
    }
}
