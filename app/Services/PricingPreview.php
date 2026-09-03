<?php

namespace App\Services;

use App\Http\Requests\Pricing\PricingPreviewRequest;
use App\Models\PrinterProfile;
use App\Models\Variant;
use App\Support\PricingInput;
use App\Support\PricingResult;

/**
 * Cola entre "uma peca descrita algures" e o PricingCalculator.
 *
 * Ha duas origens para essa descricao — um pedido HTTP (a pagina
 * /admin/calculadora e o painel de custo do formulario de variante) e uma
 * variante gravada (a aba "Producao" do produto, que sugere e aplica precos a
 * todas de uma vez). As duas montam o calculo exatamente da mesma maneira,
 * incluindo a resolucao da impressora e a queda nos valores de recurso do
 * config. Duplicar estas linhas era duas oportunidades de os numeros da maquina
 * virem de sitios diferentes.
 */
class PricingPreview
{
    public function __construct(
        private PricingCalculator $calculator,
        private PricingSettings $settings,
        private PrinterProfileService $printers,
    ) {}

    /**
     * @return array{
     *     result: array<string, mixed>|null,
     *     printerProfileId: int|null,
     *     hourlyCostMicros: int,
     *     usingFallbackRate: bool,
     * }
     */
    public function fromRequest(PricingPreviewRequest $request): array
    {
        $printer = $this->printers->resolve($request->printerProfileId());

        // Sem impressora ativa nenhuma, os numeros da maquina vem do config e a
        // pagina avisa. E o unico caminho em que nao foi ninguem que os
        // escolheu.
        $machine = $printer ?? $this->fallbackPrinter();

        // Sem tempo nao ha calculo: e a regra fundamental desta versao, e um
        // preco calculado com zero minutos era exatamente a estimativa vaga que
        // esta feature veio substituir.
        $result = $request->isCalculable()
            ? $this->calculate(
                machine: $machine,
                printerProfileId: $printer?->id,
                weightGrams: $request->weightGrams(),
                minutes: $request->printTimeMinutes(),
                pricePerKgCents: $request->pricePerKgCents(),
                packagingCostCents: $request->packagingCostCents(),
                componentsCostCents: $request->componentsCostCents(),
                activeLaborMinutes: $request->activeLaborMinutes(),
                mode: $request->mode(),
                quantity: $request->quantity(),
            )->toArray()
            : null;

        return [
            'result' => $result,
            'printerProfileId' => $printer?->id,
            // O agregado, so para a pagina poder escrever "0,16 EUR/h". O
            // calculo a serio nao passa por ele: parte dos MINUTOS e faz uma
            // divisao so por parcela. Ver PrinterProfile::hourlyCostMicros().
            'hourlyCostMicros' => $machine->hourlyCostMicros($this->settings->electricityPriceMicrosPerKwh()),
            'usingFallbackRate' => $printer === null,
        ];
    }

    /**
     * O preco que a calculadora daria a uma variante tal como esta gravada.
     *
     * Null quando nao ha conta a fazer — sem peso, sem tempo ou sem material.
     * O material e obrigatorio AQUI, ao contrario do painel do formulario de
     * variante: o painel mostra o custo de maquina de uma variante ainda sem
     * filamento porque o admin esta a preenche-la; isto e o que se GRAVA como
     * preco, e um preco que finge que o plastico e de graca engana mais do que
     * preco nenhum. A mesma regra do PricingCalculatorController.
     *
     * Sempre por unidade: a variante descreve UMA peca.
     */
    public function forVariant(Variant $variant): ?PricingResult
    {
        $minutes = $variant->printing_time_minutes ?? 0;
        $grams = $variant->filament_weight_grams ?? 0;
        $material = $variant->material;

        if ($minutes <= 0 || $grams <= 0 || $material === null) {
            return null;
        }

        $printer = $this->printers->resolve($variant->printer_profile_id);

        return $this->calculate(
            machine: $printer ?? $this->fallbackPrinter(),
            printerProfileId: $printer?->id,
            weightGrams: $grams,
            minutes: $minutes,
            pricePerKgCents: $material->price_per_kg_cents,
            packagingCostCents: $variant->packaging_cost_cents ?? 0,
            componentsCostCents: $variant->components_cost_cents ?? 0,
            activeLaborMinutes: $variant->active_labor_minutes,
        );
    }

    private function calculate(
        PrinterProfile $machine,
        ?int $printerProfileId,
        int $weightGrams,
        int $minutes,
        int $pricePerKgCents,
        int $packagingCostCents,
        int $componentsCostCents,
        ?int $activeLaborMinutes,
        string $mode = PricingInput::MODE_PER_UNIT,
        int $quantity = 1,
    ): PricingResult {
        return $this->calculator->calculate(new PricingInput(
            mode: $mode,
            weightGrams: $weightGrams,
            minutes: $minutes,
            pricePerKgCents: $pricePerKgCents,
            printerPowerWatts: $machine->average_power_watts,
            printerPurchasePriceCents: $machine->purchase_price_cents,
            printerLifetimeHours: $machine->lifetime_hours,
            printerMaintenanceMicrosPerHour: $machine->maintenance_micros_per_hour,
            packagingCostCents: $packagingCostCents,
            componentsCostCents: $componentsCostCents,
            activeLaborMinutes: $activeLaborMinutes,
            quantity: $quantity,
            printerProfileId: $printerProfileId,
        ));
    }

    /**
     * A maquina imaginaria do config, como modelo NAO gravado.
     *
     * Um objeto em vez de quatro variaveis soltas para o custo/hora ter uma
     * implementacao so: a versao anterior repetia a formula aqui, e duas
     * copias da mesma conta divergem sempre — a primeira vez que alguem mexer
     * na tarifa e so num dos sitios.
     */
    private function fallbackPrinter(): PrinterProfile
    {
        return new PrinterProfile([
            'name' => 'Impressora por omissão',
            'average_power_watts' => $this->settings->fallbackPrinterPowerWatts(),
            'purchase_price_cents' => $this->settings->fallbackPrinterPurchasePriceCents(),
            'lifetime_hours' => $this->settings->fallbackPrinterLifetimeHours(),
            'maintenance_micros_per_hour' => $this->settings->fallbackPrinterMaintenanceMicrosPerHour(),
        ]);
    }
}
