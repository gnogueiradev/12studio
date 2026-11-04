<?php

namespace App\Mcp\Tools\Read;

use App\Http\Requests\Pricing\PricingPreviewRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\McpFormat;
use App\Mcp\Presenters\PricingPresenter;
use App\Mcp\Tools\StudioTool;
use App\Models\Material;
use App\Models\User;
use App\Services\PricingLog;
use App\Services\PricingPreview;
use App\Support\PricingInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * A pagina /admin/calculadora, para o Claude: uma peca descrita a mao, sem
 * variante nenhuma por tras.
 *
 * Os campos sao os do PricingPreviewRequest e e ele que os valida — os mesmos
 * tectos, as mesmas mensagens, e o tempo em horas e minutos SEPARADOS pela
 * razao que la esta escrita. A conta e a do PricingPreview::simulate(), a
 * mesma que a pagina mostra.
 */
#[Name('pricing_calculate')]
#[Title('Calculadora de preços')]
#[Description('Orçamento de uma peça QUALQUER, sem precisar de variante nem de produto — a calculadora livre do backoffice. Dás o filamento, o peso e o tempo de impressão (e, se houver, placas, trocas manuais de cor e minutos de design); devolve o custo de produção parcela a parcela (com a mão de obra discriminada), três preços sugeridos em suggested.tiers (minimum, recommended, premium) com o lucro, a margem, o lucro por hora de impressora e a regra que decidiu cada um (margin, per_hour ou min_job), e avisos. suggested.retail_price e suggested.wholesale_price são os do Recomendado. Os preços vêm com IVA incluído (à taxa normal da loja) e terminam em ,49 ou ,99; os custos, lucros e margens são sem IVA. Tudo por unidade, com os totais do trabalho à parte. Não grava nada. Para uma variante que já existe, usa pricing_preview.')]
#[IsReadOnly]
#[IsIdempotent]
class PricingCalculateTool extends StudioTool
{
    public function __construct(
        private PricingPreview $pricing,
        private FormRequestRunner $runner,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'material_id' => $schema->integer()->required()
                ->description('Filamento (ver materials_list). É dele que vem o preço por kg.'),
            'weight_grams' => $schema->integer()->min(1)->max(50000)->required()
                ->description('Gramas de filamento: de UMA peça em per_unit, da mesa TODA em batch.'),
            'hours' => $schema->integer()->min(0)->max(999)
                ->description('Horas de impressão. O tempo é horas + minutos: 1h30 são hours 1 e minutes 30. Em batch é o tempo da mesa toda.'),
            'minutes' => $schema->integer()->min(0)->max(59)
                ->description('Minutos de impressão para além das horas (0 a 59).'),
            'mode' => $schema->string()->enum(PricingInput::MODES)
                ->description('per_unit (por omissão): o peso e o tempo descrevem uma peça. batch: descrevem a mesa inteira e o custo divide-se pela quantidade.'),
            'quantity' => $schema->integer()->min(1)->max(9999)
                ->description('Quantas peças. Em batch, quantas cabem na mesa. Por omissão 1.'),
            'printer_profile_id' => $schema->integer()
                ->description('Impressora (ver printers_list). Vazio = a predefinida.'),
            'active_labor_minutes' => $schema->integer()->min(0)->max(600)
                ->description('Minutos de trabalho manual por peça (preparar, rebarbar, embalar). Vazio = o valor das definições; 0 = não leva trabalho nenhum.'),
            'packaging_cost' => $schema->string()
                ->description('Custo de embalagem por peça em euros, ex. "0,35".'),
            'components_cost' => $schema->string()
                ->description('Custo de componentes por peça (ímanes, argolas…) em euros.'),
            'plates' => $schema->integer()->min(1)->max(100)
                ->description('Placas de impressão (de uma peça em per_unit, da mesa em batch). Por omissão 1. Cada placa a mais soma minutos de trabalho.'),
            'manual_color_changes' => $schema->integer()->min(0)->max(100)
                ->description('Trocas manuais de filamento, no mesmo âmbito das placas. Por omissão 0. Cada troca soma minutos de trabalho.'),
            'design_minutes' => $schema->integer()->min(0)->max(6000)
                ->description('Minutos de design (modelação) do trabalho todo. Cobra-se uma vez e só no preço Premium; divide-se pela quantidade.'),
            'reference_filament_cost' => $schema->string()
                ->description('Custo do filamento que o slicer mostra (ex. Bambu Studio), em euros com IVA, para o mesmo peso. Avisa se diferir mais de 15% do calculado.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $input = $this->runner->resolve(PricingPreviewRequest::class, $request->all(), $user);

        // Sem filamento nao ha preco, mesmo havendo peso e tempo: o plastico
        // saia a zero. A mesma regra do PricingCalculatorController, e aqui
        // pela mesma razao que la — o isCalculable() do pedido e partilhado
        // com a ficha de variante, onde o material e opcional.
        $material = $input->materialId() === null ? null : Material::query()->find($input->materialId());

        if ($material === null) {
            return Response::error('Falta o filamento: indica material_id (ver materials_list). Sem ele o plástico saía de graça.');
        }

        // Um orcamento: placas, trocas de cor, minimo de trabalho e lucro
        // minimo por trabalho entram todos. A mesma conta da pagina.
        $simulation = $this->pricing->simulate($input, null, quote: true);
        $result = $simulation['result'];

        if ($result === null) {
            return Response::error('Falta o peso (weight_grams) ou o tempo de impressão (hours e/ou minutes) — sem os dois não há cálculo.');
        }

        PricingLog::calculated('mcp.pricing_calculate', $material->id, $input->weightGrams(), $result);

        $printer = $simulation['printer'];
        $asked = $input->printerProfileId();

        return Response::structured([
            'inputs' => [
                'mode' => $result->mode,
                'quantity' => $result->quantity,
                'material' => [
                    'id' => $material->id,
                    'name' => $material->name,
                    'price_per_kg' => McpFormat::money($material->price_per_kg_cents),
                ],
                'weight_grams' => $input->weightGrams(),
                'print_time_minutes' => $input->printTimeMinutes(),
                // Do trabalho todo: em batch inclui a preparacao da mesa.
                'labor_minutes' => $result->laborMinutes,
                'plates' => $result->plates,
                'manual_color_changes' => $result->manualColorChanges,
                'design_minutes' => $result->designMinutes,
            ],
            'printer' => [
                'id' => $printer?->id,
                'name' => $printer === null ? 'Impressora por omissão' : $printer->name,
                'hourly_cost' => McpFormat::micros($simulation['hourlyCostMicros']),
                'note' => match (true) {
                    $printer === null => 'Não há nenhuma impressora ativa: os números da máquina vêm dos valores por omissão.',
                    $asked !== null && $asked !== $printer->id => 'A impressora pedida está arquivada: usou-se a predefinida.',
                    default => null,
                },
            ],
            'suggested' => PricingPresenter::suggested($result),
            'costs' => PricingPresenter::costs($result),
            'labor' => PricingPresenter::labor($result),
            'profit' => PricingPresenter::profits($result),
            'margins_percent' => PricingPresenter::margins($result),
            'job' => PricingPresenter::job($result),
            'warnings' => PricingPresenter::warnings($result),
        ]);
    }
}
