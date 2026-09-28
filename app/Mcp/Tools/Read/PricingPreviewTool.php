<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\McpFormat;
use App\Mcp\Tools\StudioTool;
use App\Models\User;
use App\Models\Variant;
use App\Services\PricingPreview;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('pricing_preview')]
#[Title('Preço sugerido de uma variante')]
#[Description('Calcula o custo de produção e os preços sugeridos (retalho e revenda) de uma variante, com a mesma calculadora do backoffice: filamento, energia, depreciação e manutenção da impressora, mão de obra, embalagem e componentes. Compara com o preço atual. Não altera nada. Precisa de a variante ter peso, tempo de impressão e material preenchidos.')]
#[IsReadOnly]
#[IsIdempotent]
class PricingPreviewTool extends StudioTool
{
    public function __construct(
        private PricingPreview $pricing,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'variant_id' => $schema->integer()->required()->description('Id da variante (ver product_get).'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['variant_id' => ['required', 'integer']]);

        // A regra `integer` aceita "5" em texto; o find() quer mesmo um id.
        $variant = Variant::query()->with(['product', 'material'])->whereKey((int) $data['variant_id'])->first();

        if ($variant === null) {
            return Response::error('Variante não encontrada.');
        }

        $result = $this->pricing->forVariant($variant);

        if ($result === null) {
            return Response::error('Falta peso do filamento, tempo de impressão ou material nesta variante — sem isso não há cálculo.');
        }

        return Response::structured([
            'variant_id' => $variant->id,
            'product' => $variant->product->name,
            'sku' => $variant->sku,
            'current' => [
                'normal_price' => McpFormat::money($variant->normalPriceCents()),
                'sale_price' => McpFormat::moneyOrNull($variant->salePriceCents()),
                'wholesale_price' => McpFormat::moneyOrNull($variant->wholesale_price_cents),
            ],
            'suggested' => [
                'retail_price' => McpFormat::micros($result->retailPriceMicros),
                'wholesale_price' => McpFormat::micros($result->wholesalePriceMicros),
            ],
            'costs' => [
                'filament' => McpFormat::micros($result->filamentCostMicros),
                'electricity' => McpFormat::micros($result->electricityCostMicros),
                'depreciation' => McpFormat::micros($result->depreciationCostMicros),
                'maintenance' => McpFormat::micros($result->maintenanceCostMicros),
                'labor' => McpFormat::micros($result->laborCostMicros),
                'packaging' => McpFormat::micros($result->packagingCostMicros),
                'components' => McpFormat::micros($result->componentsCostMicros),
                'failure_reserve' => McpFormat::micros($result->failureCostMicros()),
                'production_total' => McpFormat::micros($result->productionCostMicros),
            ],
            'margins_percent' => [
                'direct_sale' => round($result->directMarginBp() / 100, 1),
                'wholesale' => round($result->wholesaleMarginBp() / 100, 1),
                'reseller' => round($result->resellerMarginBp() / 100, 1),
            ],
        ]);
    }
}
