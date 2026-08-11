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
            'currencies' => Setting::CURRENCIES,
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $this->settings->set('currency', $request->validated()['currency']);

        $this->toast('Definições guardadas.');

        return to_route('admin.definicoes.index');
    }
}
