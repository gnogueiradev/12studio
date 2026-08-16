<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Printer\StorePrinterProfileRequest;
use App\Http\Requests\Printer\UpdatePrinterProfileRequest;
use App\Models\PrinterProfile;
use App\Services\PricingSettings;
use App\Services\PrinterProfileService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Perfis de impressora: uma maquina e os numeros de que o seu custo/hora se faz.
 *
 * As tres parcelas de maquina de cada peca saem daqui — energia, depreciacao e
 * manutencao — e juntas sao a segunda maior fatia depois do filamento, alem de
 * serem as unicas que crescem com o tempo de impressao. Uma A1 e uma P1S nao
 * custam o mesmo por hora; por isso isto e uma tabela e nao uma definicao
 * unica em /admin/definicoes. A tarifa da luz, essa, e mesmo global e vive la.
 */
class PrinterProfileController extends Controller
{
    public function __construct(
        private PrinterProfileService $profiles,
        private PricingSettings $settings,
    ) {}

    public function index(): Response
    {
        /** @var Collection<int, PrinterProfile> $profiles */
        $profiles = PrinterProfile::query()
            ->withCount('variants')
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $tariff = $this->settings->electricityPriceMicrosPerKwh();

        return Inertia::render('admin/impressoras/index', [
            'printers' => $profiles->map(fn (PrinterProfile $profile): array => [
                'id' => $profile->id,
                'name' => $profile->name,
                'averagePowerWatts' => $profile->average_power_watts,
                'purchasePriceCents' => $profile->purchase_price_cents,
                'lifetimeHours' => $profile->lifetime_hours,
                'maintenanceMicrosPerHour' => $profile->maintenance_micros_per_hour,
                // O agregado que as quatro colunas produzem. Vai calculado do
                // servidor para a tarifa nao ter de viajar ate ao browser.
                'hourlyCostMicros' => $profile->hourlyCostMicros($tariff),
                'notes' => $profile->notes,
                'isDefault' => $profile->is_default,
                'active' => $profile->active,
                'sortOrder' => $profile->sort_order,
                'state' => $profile->state(),
                // Quantas variantes ficam presas a esta maquina — e o que
                // explica porque e que arquivar e a unica saida.
                'variantsCount' => (int) $profile->variants_count,
            ]),
            'stats' => $this->stats($profiles, $tariff),
            // O modal precisa dela para pre-visualizar o EUR/h enquanto se
            // escreve; o valor a serio continua a ser o que o servidor devolve.
            'electricityPriceMicrosPerKwh' => $tariff,
        ]);
    }

    public function store(StorePrinterProfileRequest $request): RedirectResponse
    {
        $this->profiles->store($request->validated());

        $this->toast('Impressora criada.');

        return to_route('admin.impressoras.index');
    }

    public function update(UpdatePrinterProfileRequest $request, PrinterProfile $printerProfile): RedirectResponse
    {
        $this->profiles->update($printerProfile, $request->validated());

        $this->toast('Impressora atualizada.');

        return to_route('admin.impressoras.index');
    }

    /**
     * "Apagar" no admin = arquivar (regra global de eliminacao logica). As
     * variantes que ja usam esta impressora ficam intactas — a FK e
     * restrictOnDelete de proposito.
     */
    public function destroy(PrinterProfile $printerProfile): RedirectResponse
    {
        $this->profiles->archive($printerProfile);

        $this->toast('Impressora arquivada.');

        return to_route('admin.impressoras.index');
    }

    public function restore(PrinterProfile $printerProfile): RedirectResponse
    {
        $this->profiles->restore($printerProfile);

        $this->toast('Impressora restaurada.');

        return back();
    }

    /**
     * Rota propria em vez de um campo do formulario: escolher a predefinida e
     * um gesto de um clique na listagem, e obrigar a abrir o modal da maquina
     * para trocar uma caixa era ceremonia a mais. Por isso o `is_default` nao
     * vai no payload do modal — quem manda nele e o botao da linha.
     */
    public function setDefault(PrinterProfile $printerProfile): RedirectResponse
    {
        $this->profiles->setDefault($printerProfile);

        $this->toast("A calculadora passa a usar a {$printerProfile->name}.");

        return back();
    }

    /**
     * Metricas do topo. Calculadas sobre a coleccao ja carregada, como na
     * listagem de materiais: sao meia duzia de linhas que a pagina ja trouxe.
     *
     * @param  Collection<int, PrinterProfile>  $profiles
     * @return array{activeCount: int, defaultName: string|null, defaultHourlyCostMicros: int|null, averageHourlyCostMicros: int}
     */
    private function stats(Collection $profiles, int $tariff): array
    {
        $active = $profiles->filter(fn (PrinterProfile $profile): bool => $profile->active);
        $default = $active->firstWhere('is_default', true);

        $costs = $active->map(
            fn (PrinterProfile $profile): int => $profile->hourlyCostMicros($tariff)
        );

        return [
            'activeCount' => $active->count(),
            'defaultName' => $default?->name,
            'defaultHourlyCostMicros' => $default?->hourlyCostMicros($tariff),
            // `avg` devolve null com a coleccao vazia — o cartao quer um numero
            // para formatar, nao um buraco.
            'averageHourlyCostMicros' => (int) round((float) $costs->avg()),
        ];
    }
}
