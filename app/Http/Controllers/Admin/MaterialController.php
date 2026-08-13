<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Material\StoreMaterialRequest;
use App\Http\Requests\Material\UpdateMaterialRequest;
use App\Models\Material;
use App\Services\MaterialService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Material = a bobine (PLA, PETG). E ele que tem preco por kg, fornecedor e
 * stock, e e daqui que sai o custo de filamento de cada peca.
 *
 * Nao tem cores: a cor e um eixo a parte, e o mesmo "Preto" imprime-se em
 * qualquer material.
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
            ->withCount('variants')
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
                // Quantas variantes dependem desta bobine. Substitui os
                // swatches de cor que aqui estavam: e o numero acionavel — diz
                // se o material esta a ser usado e porque nao se apaga.
                'variantsCount' => (int) $material->variants_count,
            ]),
            'stats' => $this->stats($materials),
            'families' => Material::FAMILIES,
        ]);
    }

    public function store(StoreMaterialRequest $request): RedirectResponse
    {
        $this->materialService->store($request->validated());

        $this->toast('Material criado.');

        return to_route('admin.materiais.index');
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
