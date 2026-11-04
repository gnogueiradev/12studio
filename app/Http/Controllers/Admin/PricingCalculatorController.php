<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\PricingPreviewRequest;
use App\Services\PricingLog;
use App\Services\PricingPreview;
use App\Services\PricingSettings;
use App\Support\MaterialOptions;
use App\Support\PricingInput;
use App\Support\PrinterOptions;
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
        private PricingSettings $settings,
    ) {}

    public function __invoke(PricingPreviewRequest $request): Response
    {
        // So e um ORCAMENTO quando o toggle "Trabalho por encomenda" esta
        // ligado: ai entram placas, trocas de cor, design, minimo de trabalho e
        // lucro minimo por trabalho. Desligado e uma peca de catalogo — a mesma
        // conta das variantes.
        $simulation = $this->preview->simulate($request, null, quote: $request->isQuote());
        $result = $request->materialId() === null ? null : $simulation['result'];
        $price = $request->priceCents();

        if ($result !== null) {
            PricingLog::calculated('calculadora', $request->materialId(), $request->weightGrams(), $result, $price);
        }

        $preview = [
            'result' => $result?->toArray(),
            'printerProfileId' => $simulation['printer']?->id,
            'hourlyCostMicros' => $simulation['hourlyCostMicros'],
            'usingFallbackRate' => $simulation['printer'] === null,
        ];

        return Inertia::render('admin/calculadora/index', [
            'inputs' => [
                ...$request->toFormFields(),
                // A impressora efetivamente usada, que pode nao ser a que veio
                // no pedido: uma arquivada cai na predefinida.
                'printer_profile_id' => $preview['printerProfileId'],
            ],
            // Sem filamento escolhido nao ha preco, mesmo havendo peso e tempo.
            // O filamento so pode vir da loja, portanto sem material o custo do
            // plastico seria zero — e um preco que finge que o plastico e de
            // graca engana mais do que preco nenhum.
            //
            // A regra fica AQUI e nao no isCalculable() do pedido porque esse e
            // partilhado com a ficha de variante, onde o material e opcional:
            // exigi-lo la apagava em silencio a sugestao de todas as variantes
            // que ainda nao tem material.
            'result' => $preview['result'],
            // O preco escolhido face aos niveis. Null sem preco ou sem calculo.
            'evaluation' => $result === null || $price === null ? null : $result->evaluate($price)->toArray(),
            'hourlyCostMicros' => $preview['hourlyCostMicros'],
            'usingFallbackRate' => $preview['usingFallbackRate'],
            'printers' => PrinterOptions::all($this->settings->electricityPriceMicrosPerKwh()),
            // O valor por omissao serve de placeholder ao campo do trabalho
            // ativo: um campo vazio tem de dizer o que acontece se ficar vazio.
            'defaultActiveLaborMinutes' => $this->settings->activeLaborMinutes(),
            'materials' => MaterialOptions::all($request->materialId()),
            'modes' => $this->modeOptions(),
        ]);
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
