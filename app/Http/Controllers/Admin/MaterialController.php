<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Material\StoreMaterialRequest;
use App\Http\Requests\Material\UpdateMaterialRequest;
use App\Models\Color;
use App\Models\Material;
use App\Services\MaterialService;
use App\Support\ColorPalette;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Material = familia de filamento (PLA, PETG). O preco por kg daqui e a base
 * do custo de cada peca; cada cor pode fazer override do seu.
 */
class MaterialController extends Controller
{
    public function __construct(
        private MaterialService $materialService,
    ) {}

    public function index(): Response
    {
        /** @var Collection<int, Material> $materials */
        $materials = Material::query()
            ->with(['colors' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/materiais/index', [
            'materials' => $materials->map(fn (Material $material): array => [
                'id' => $material->id,
                'name' => $material->name,
                'family' => $material->family,
                'supplier' => $material->supplier,
                'pricePerKgCents' => $material->price_per_kg_cents,
                'spoolsInStock' => $material->spools_in_stock,
                'minSpools' => $material->min_spools,
                'active' => $material->active,
                'sortOrder' => $material->sort_order,
                'state' => $material->state(),
                // Vao todas; o corte nos primeiros swatches e o "+N" sao
                // decisao de apresentacao e ficam do lado do React.
                'colors' => $material->colors
                    ->map(fn (Color $color): array => [
                        'name' => $color->name,
                        'hex' => $color->hex_color,
                    ])
                    ->values(),
            ]),
            'stats' => $this->stats($materials),
            // As chips do modal sao a paleta que o admin construiu em
            // /admin/cores, nao uma lista fixa. Nao se chama `palette` por isso
            // mesmo: neste projeto "paleta" sao os presets do FilamentPalette,
            // e o formulario de nova cor continua a receber esses.
            'colorOptions' => ColorPalette::all(),
            'families' => Material::FAMILIES,
        ]);
    }

    public function store(StoreMaterialRequest $request): RedirectResponse
    {
        $this->materialService->store($request->validated());

        $this->toast('Material criado.');

        return to_route('admin.materiais.index');
    }

    public function edit(Material $material): Response
    {
        return Inertia::render('admin/materiais/edit', [
            'material' => [
                'id' => $material->id,
                'name' => $material->name,
                'family' => $material->family,
                'supplier' => $material->supplier,
                'pricePerKg' => Money::toDecimal($material->price_per_kg_cents),
                'spoolsInStock' => $material->spools_in_stock,
                'minSpools' => $material->min_spools,
                'active' => $material->active,
                'sortOrder' => $material->sort_order,
            ],
            'families' => Material::FAMILIES,
        ]);
    }

    public function update(UpdateMaterialRequest $request, Material $material): RedirectResponse
    {
        $this->materialService->update($material, $request->validated());

        $this->toast('Material atualizado.');

        return to_route('admin.materiais.index');
    }

    /**
     * "Apagar" no admin = arquivar (regra global de eliminacao logica).
     */
    public function destroy(Material $material): RedirectResponse
    {
        $this->materialService->archive($material);

        $this->toast('Material arquivado.');

        return to_route('admin.materiais.index');
    }

    public function restore(Material $material): RedirectResponse
    {
        $this->materialService->restore($material);

        $this->toast('Material restaurado.');

        return back();
    }

    /**
     * Metricas do topo da listagem.
     *
     * Calculadas sobre a coleccao ja carregada em vez de com agregados em SQL:
     * sao meia duzia de linhas que a pagina ja trouxe, e "abaixo do minimo"
     * depende do Material::state(), que e a unica fonte do estado. Repetir a
     * regra num selectRaw era a segunda oportunidade de ela divergir.
     *
     * Arquivados ficam de fora de todas: o que esta arquivado nao se compra
     * nem se conta como falta.
     *
     * @param  Collection<int, Material>  $materials
     * @return array{activeCount: int, spoolsTotal: int, averagePricePerKgCents: int, belowMinimumCount: int}
     */
    private function stats(Collection $materials): array
    {
        $active = $materials->filter(fn (Material $material): bool => $material->active);

        return [
            'activeCount' => $active->count(),
            'spoolsTotal' => (int) $active->sum('spools_in_stock'),
            // `avg` devolve null com a coleccao vazia — a listagem quer um
            // numero para formatar, nao um buraco.
            'averagePricePerKgCents' => (int) round((float) $active->avg('price_per_kg_cents')),
            'belowMinimumCount' => $active->filter(fn (Material $material): bool => $material->isLowStock())->count(),
        ];
    }
}
