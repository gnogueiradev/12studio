<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyMargin;
use App\Etsy\EtsySettings;
use App\Mcp\McpFormat;
use App\Mcp\Tools\StudioTool;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('etsy_pricing_preview')]
#[Title('Margem na Etsy contra o site')]
#[Description('Cada variante com o que rende no site e o que renderia na Etsy, lado a lado: preço, valor sem IVA, taxas da Etsy, o que sobra ("net"), custo de produção, lucro e margem. Serve para decidir a política de preço da Etsy: o mesmo preço do site, uma margem global, ou um preço próprio por variante (etsy_listing_save). Com "product_id" vê um produto; sem ele, todas as variantes ativas (até 200). As taxas da Etsy são a ESTIMATIVA das definições ("assumptions"), não as taxas reais de cada venda. Sem peso, tempo e material na variante não há custo nem lucro (vêm a null). É conta nossa: não faz pedidos à Etsy.')]
#[IsReadOnly]
#[IsIdempotent]
class EtsyPricingPreviewTool extends StudioTool
{
    private const MAX_ROWS = 200;

    public function __construct(
        private EtsyMargin $margin,
        private EtsySettings $settings,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->min(1)->description('Id do produto. Sem ele vêm todas as variantes ativas.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['product_id' => ['nullable', 'integer', 'min:1']]);

        $query = Variant::query()
            ->where('active', true)
            ->with(['product', 'material', 'etsyVariant'])
            ->orderBy('product_id')
            ->orderBy('id');

        if (isset($data['product_id'])) {
            if (! Product::query()->whereKey((int) $data['product_id'])->exists()) {
                return Response::error('Produto não encontrado.');
            }

            $query->where('product_id', (int) $data['product_id']);
        } else {
            $query->whereHas('product', fn (Builder $products) => $products->where('status', '!=', 'archived'));
        }

        $variants = $query->limit(self::MAX_ROWS + 1)->get();

        return Response::structured([
            'variants' => $variants->take(self::MAX_ROWS)->map(function (Variant $variant): array {
                $row = $this->margin->compare($variant);

                return [
                    'variant_id' => $variant->id,
                    'product' => $variant->product->name,
                    'sku' => $row['sku'],
                    // own = preco proprio na Etsy; markup = site + margem global; site = o do site.
                    'price_source' => $row['price_source'],
                    'site' => $this->side($row['site']),
                    'etsy' => $this->side($row['etsy']),
                ];
            })->values()->all(),
            'truncated' => $variants->count() > self::MAX_ROWS,
            'assumptions' => [
                'markup_percent' => $this->percent($this->settings->markupBp()),
                'fee_percent' => $this->percent($this->settings->feePercentageBp()),
                'fee_fixed' => McpFormat::money($this->settings->feeFixedCents()),
                'note' => 'As taxas incidem sobre o que o comprador paga (com IVA) e são uma estimativa, acertada no backoffice em Etsy → Preços. O lado do site não leva taxas.',
            ],
        ]);
    }

    /**
     * @param  array<string, int|null>  $side
     * @return array<string, mixed>
     */
    private function side(array $side): array
    {
        return [
            'price' => McpFormat::money((int) $side['price_cents']),
            'ex_vat' => McpFormat::money((int) $side['ex_vat_cents']),
            'fees' => McpFormat::money((int) $side['fees_cents']),
            'net' => McpFormat::money((int) $side['net_cents']),
            'cost' => McpFormat::moneyOrNull($side['cost_cents']),
            'profit' => McpFormat::moneyOrNull($side['profit_cents']),
            'margin_percent' => $side['margin_bp'] === null ? null : $this->percent($side['margin_bp']),
        ];
    }

    private function percent(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, '.', ''), '0'), '.');
    }
}
