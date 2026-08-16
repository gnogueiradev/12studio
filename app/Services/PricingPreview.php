<?php

namespace App\Services;

use App\Http\Requests\Pricing\PricingPreviewRequest;
use App\Models\PrinterProfile;
use App\Support\PricingInput;

/**
 * Cola entre um pedido HTTP e o PricingCalculator.
 *
 * Existe para a pagina /admin/calculadora e o formulario de variante montarem o
 * calculo exatamente da mesma maneira — incluindo a resolucao da impressora e o
 * aviso de que se caiu nos valores de recurso do config. Duplicar estas linhas
 * nos dois controladores era duas oportunidades de os numeros da maquina virem
 * de sitios diferentes.
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
            ? $this->calculator->calculate(new PricingInput(
                mode: $request->mode(),
                weightGrams: $request->weightGrams(),
                minutes: $request->printTimeMinutes(),
                pricePerKgCents: $request->pricePerKgCents(),
                printerPowerWatts: $machine->average_power_watts,
                printerPurchasePriceCents: $machine->purchase_price_cents,
                printerLifetimeHours: $machine->lifetime_hours,
                printerMaintenanceMicrosPerHour: $machine->maintenance_micros_per_hour,
                packagingCostCents: $request->packagingCostCents(),
                componentsCostCents: $request->componentsCostCents(),
                activeLaborMinutes: $request->activeLaborMinutes(),
                quantity: $request->quantity(),
                printerProfileId: $printer?->id,
            ))->toArray()
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
