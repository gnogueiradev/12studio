<?php

namespace App\Services;

use App\Http\Requests\Pricing\PricingPreviewRequest;
use App\Support\PricingInput;

/**
 * Cola entre um pedido HTTP e o PricingCalculator.
 *
 * Existe para a pagina /admin/calculadora e o formulario de variante montarem o
 * calculo exatamente da mesma maneira — incluindo a resolucao da impressora e o
 * aviso de que se caiu no custo/hora do config. Duplicar estas dez linhas nos
 * dois controladores era duas oportunidades de a taxa vir de sitios diferentes.
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
     *     hourlyRateCents: int,
     *     usingFallbackRate: bool,
     * }
     */
    public function fromRequest(PricingPreviewRequest $request): array
    {
        $printer = $this->printers->resolve($request->printerProfileId());

        // Sem impressora ativa nenhuma, o custo/hora vem do config e a pagina
        // avisa. E o unico caminho em que a taxa nao e escolhida por ninguem.
        $hourlyRateCents = $printer === null
            ? $this->settings->fallbackHourlyRateCents()
            : $printer->hourly_rate_cents;

        // Sem tempo nao ha calculo: e a regra fundamental desta versao, e um
        // preco calculado com zero minutos era exatamente a estimativa vaga que
        // esta feature veio substituir.
        $result = $request->isCalculable()
            ? $this->calculator->calculate(new PricingInput(
                mode: $request->mode(),
                weightGrams: $request->weightGrams(),
                minutes: $request->printTimeMinutes(),
                pricePerKgCents: $request->pricePerKgCents(),
                hourlyRateCents: $hourlyRateCents,
                extraCostCents: $request->extraCostCents(),
                quantity: $request->quantity(),
                printerProfileId: $printer?->id,
            ))->toArray()
            : null;

        return [
            'result' => $result,
            'printerProfileId' => $printer?->id,
            'hourlyRateCents' => $hourlyRateCents,
            'usingFallbackRate' => $printer === null,
        ];
    }
}
