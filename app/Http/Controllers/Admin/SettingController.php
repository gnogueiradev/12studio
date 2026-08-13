<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\UpdateSettingsRequest;
use App\Models\Setting;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Definicoes que o dono pode mudar sem deploy. Por agora so a moeda; os
 * parametros de custo (kWh, wattagem, custo/hora) entram aqui na Fase 2,
 * quando o CostService lhes der uso.
 */
class SettingController extends Controller
{
    public function __construct(
        private SettingService $settings,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/definicoes/index', [
            'settings' => [
                'currency' => $this->settings->currency(),
            ],
            'currencies' => $this->currencyOptions(),
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

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $this->settings->set('currency', $request->validated()['currency']);

        $this->toast('Definições guardadas.');

        return to_route('admin.definicoes.index');
    }
}
