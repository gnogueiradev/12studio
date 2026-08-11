<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Material\StoreMaterialRequest;
use App\Http\Requests\Material\UpdateMaterialRequest;
use App\Models\Material;
use App\Services\MaterialService;
use App\Support\Money;
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
        $materials = Material::query()
            ->withCount('colors')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Material $material): array => [
                'id' => $material->id,
                'name' => $material->name,
                'pricePerKgCents' => $material->price_per_kg_cents,
                'active' => $material->active,
                'sortOrder' => $material->sort_order,
                'colorsCount' => $material->colors_count,
            ]);

        return Inertia::render('admin/materiais/index', [
            'materials' => $materials,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/materiais/create');
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
                'pricePerKg' => Money::toDecimal($material->price_per_kg_cents),
                'active' => $material->active,
                'sortOrder' => $material->sort_order,
            ],
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
}
