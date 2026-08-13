<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\PricingPreviewRequest;
use App\Models\PrinterProfile;
use App\Services\PricingPreview;
use App\Support\ColorGroups;
use App\Support\PricingInput;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calculadora de precos: simulacao livre, sem gravar nada.
 *
 * O estado vive no URL e nao no servidor — o calculo e uma funcao pura dos
 * campos, e assim um preco fica partilhavel ("olha o que da esta peca a 1h30").
 * Por isso um GET, e por isso a pagina recarrega so a prop `result` a cada
 * alteracao em vez de espelhar a formula em TypeScript: e o mesmo motor que
 * calcula o preview e o que calcula o preco gravado.
 */
class PricingCalculatorController extends Controller
{
    public function __construct(
        private PricingPreview $preview,
    ) {}

    public function __invoke(PricingPreviewRequest $request): Response
    {
        $preview = $this->preview->fromRequest($request);

        return Inertia::render('admin/calculadora/index', [
            'inputs' => [
                ...$request->toFormFields(),
                // A impressora efetivamente usada, que pode nao ser a que veio
                // no pedido: uma arquivada cai na predefinida.
                'printer_profile_id' => $preview['printerProfileId'],
            ],
            'result' => $preview['result'],
            'hourlyRateCents' => $preview['hourlyRateCents'],
            'usingFallbackRate' => $preview['usingFallbackRate'],
            'printers' => $this->printerOptions(),
            'colorGroups' => ColorGroups::all($request->colorId()),
            'modes' => $this->modeOptions(),
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

    /**
     * Formato { value, label } — o mesmo `Option` do resto do frontend. A lista
     * vem do servidor, como as moedas nas definicoes, para nao haver uma gemea
     * em TypeScript a divergir das constantes do PricingInput.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function modeOptions(): array
    {
        return [
            ['value' => PricingInput::MODE_PER_UNIT, 'label' => 'Uma peça de cada vez'],
            ['value' => PricingInput::MODE_BATCH, 'label' => 'Várias na mesma mesa'],
        ];
    }
}
