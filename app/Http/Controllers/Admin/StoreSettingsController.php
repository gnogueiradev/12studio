<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\UpdateStoreSettingsRequest;
use App\Services\SettingService;
use App\Services\StoreSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Definicoes -> Montra: abrir a loja ao publico e os contactos que ela mostra.
 * A traducao entre o formulario e as chaves da tabela `settings` vive toda no
 * StoreSettings — o controlador so passa o payload.
 */
class StoreSettingsController extends Controller
{
    public function __construct(
        private SettingService $settings,
        private StoreSettings $store,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/definicoes/montra', [
            'store' => $this->store->toForm(),
            'networks' => $this->store->networkOptions(),
        ]);
    }

    public function update(UpdateStoreSettingsRequest $request): RedirectResponse
    {
        $wasOpen = $this->store->isOpen();

        $this->settings->setMany($this->store->fromForm($request->validated()));

        $open = $this->store->isOpen();

        $this->toast(match (true) {
            $open && ! $wasOpen => 'Montra aberta ao público.',
            ! $open && $wasOpen => 'Montra fechada. Os visitantes voltam a ver o "em breve".',
            default => 'Montra atualizada.',
        });

        return to_route('admin.montra.index');
    }
}
