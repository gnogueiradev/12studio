<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\UpdatePricingSettingsRequest;
use App\Http\Requests\Setting\UpdateSettingsRequest;
use App\Models\Setting;
use App\Services\PricingSettings;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Definicoes que o dono pode mudar sem deploy: a moeda da loja e os parametros
 * da calculadora de precos.
 *
 * Dois formularios e duas rotas, e nao um so. Um endpoint unico obrigava todas
 * as regras de preco a `sometimes` — e uma gravacao parcial saltava a validacao
 * em silencio — e a pagina precisa de dois estados "por guardar" independentes
 * de qualquer forma: quem vem mudar o simbolo da moeda nao quer levar com um
 * erro numa faixa de manuseamento que nao tocou.
 */
class SettingController extends Controller
{
    public function __construct(
        private SettingService $settings,
        private PricingSettings $pricing,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/definicoes/index', [
            'settings' => [
                'currency' => $this->settings->currency(),
            ],
            'currencies' => $this->currencyOptions(),
            'pricing' => $this->pricing->toForm(),
        ]);
    }

    /**
     * Formato { value, label } — o mesmo `Option` que o resto do frontend usa
     * (resources/js/lib/options.ts). A lista vai do servidor em vez de haver
     * uma gemea em TypeScript: aqui, ao contrario das cores das categorias,
     * so a pagina das definicoes precisa dela.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function currencyOptions(): array
    {
        return collect(Setting::CURRENCIES)
            ->map(fn (string $name, string $code): array => [
                'value' => $code,
                'label' => $name,
            ])
            ->values()
            ->all();
    }

    /**
     * Agnostico da chave: o que existe e o que a UpdateSettingsRequest validou.
     * Quem sabe que definicoes ha e o Form Request, nao o controlador.
     */
    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $this->settings->setMany($request->validated());

        $this->toast('Definições guardadas.');

        return to_route('admin.definicoes.index');
    }

    /**
     * Os parametros da calculadora. A traducao entre o que o formulario mostra
     * (percentagem, euros, "1,75") e o que se guarda (pontos base, centimos)
     * vive toda no PricingSettings — o controlador so passa o payload.
     */
    public function updatePricing(UpdatePricingSettingsRequest $request): RedirectResponse
    {
        $this->settings->setMany($this->pricing->fromForm($request->validated()));

        $this->toast('Preços atualizados.');

        return to_route('admin.definicoes.index');
    }
}
