<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\McpFormat;
use App\Mcp\Presenters\PricingPresenter;
use App\Mcp\Tools\StudioTool;
use App\Models\User;
use App\Models\Variant;
use App\Services\PricingLog;
use App\Services\PricingPreview;
use App\Support\Micros;
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
#[Description('Calcula o custo de produção e os preços sugeridos de uma variante, com a mesma calculadora do backoffice: filamento, energia, depreciação e manutenção da impressora, mão de obra, embalagem e componentes. Devolve três preços em suggested.tiers (minimum, recommended, premium) com o lucro, a margem, o lucro por hora de impressora e a regra que decidiu cada um (margin ou per_hour — o lucro mínimo por trabalho é só para orçamentos); suggested.retail_price e suggested.wholesale_price são os do Recomendado. Os preços vêm com IVA incluído e terminam em ,49 ou ,99; os custos e as margens são sem IVA. Compara com o preço atual e avisa se está abaixo do Mínimo. Não altera nada. Precisa de a variante ter peso, tempo de impressão e material preenchidos. Para uma peça que ainda não é variante nenhuma, usa pricing_calculate.')]
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

        $current = $variant->normalPriceCents();
        $minimumCents = Micros::toCents($result->tiers->minimum->priceMicros);

        PricingLog::calculated('mcp.pricing_preview', $variant->material_id, (int) $variant->filament_weight_grams, $result);

        if ($current < $minimumCents) {
            PricingLog::belowMinimum($variant->id, $current, $minimumCents);
        }

        return Response::structured([
            'variant_id' => $variant->id,
            'product' => $variant->product->name,
            'sku' => $variant->sku,
            'current' => [
                'normal_price' => McpFormat::money($current),
                'sale_price' => McpFormat::moneyOrNull($variant->salePriceCents()),
                'wholesale_price' => McpFormat::moneyOrNull($variant->wholesale_price_cents),
            ],
            'suggested' => PricingPresenter::suggested($result),
            'costs' => PricingPresenter::costs($result),
            'labor' => PricingPresenter::labor($result),
            'margins_percent' => PricingPresenter::margins($result),
            // Compara-se o preco NORMAL: uma promocao abaixo do Minimo e uma
            // escolha temporaria, nao um preco mal calculado.
            'warnings' => PricingPresenter::warnings($result, $current),
        ]);
    }
}
