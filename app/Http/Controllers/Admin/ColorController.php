<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Color\StoreColorRequest;
use App\Http\Requests\Color\UpdateColorRequest;
use App\Models\Color;
use App\Models\Material;
use App\Services\ColorService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Uma cor pertence a UM material: "PLA Preto" e "PETG Preto" sao linhas
 * distintas. Isto torna impossivel vender combinacoes que nao existem e da
 * swatches reais agrupados por material na montra.
 *
 * Recurso proprio (nao aninhado no material) porque a paleta gere-se toda de
 * uma vez, nao material a material.
 */
class ColorController extends Controller
{
    public function __construct(
        private ColorService $colorService,
    ) {}

    public function index(): Response
    {
        $colors = Color::query()
            ->with('material')
            ->withCount('variants')
            ->orderBy('material_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Color $color): array => [
                'id' => $color->id,
                'name' => $color->name,
                'hexColor' => $color->hex_color,
                'material' => $color->material->name,
                'materialId' => $color->material_id,
                'effectivePricePerKgCents' => $color->effectivePricePerKgCents(),
                'hasOwnPrice' => $color->price_per_kg_cents !== null,
                'isActive' => $color->is_active,
                'sortOrder' => $color->sort_order,
                'variantsCount' => $color->variants_count,
            ]);

        return Inertia::render('admin/cores/index', [
            'colors' => $colors,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/cores/create', [
            'materials' => $this->materialOptions(),
        ]);
    }

    public function store(StoreColorRequest $request): RedirectResponse
    {
        $this->colorService->store($request->validated());

        $this->toast('Cor criada.');

        return to_route('admin.cores.index');
    }

    public function edit(Color $color): Response
    {
        return Inertia::render('admin/cores/edit', [
            'color' => [
                'id' => $color->id,
                'materialId' => $color->material_id,
                'name' => $color->name,
                'hexColor' => $color->hex_color,
                'pricePerKg' => $color->price_per_kg_cents === null
                    ? null
                    : Money::toDecimal($color->price_per_kg_cents),
                'isActive' => $color->is_active,
                'sortOrder' => $color->sort_order,
            ],
            'materials' => $this->materialOptions($color->material_id),
        ]);
    }

    public function update(UpdateColorRequest $request, Color $color): RedirectResponse
    {
        $this->colorService->update($color, $request->validated());

        $this->toast('Cor atualizada.');

        return to_route('admin.cores.index');
    }

    /**
     * "Apagar" no admin = arquivar (regra global de eliminacao logica).
     */
    public function destroy(Color $color): RedirectResponse
    {
        $this->colorService->archive($color);

        $this->toast('Cor arquivada.');

        return to_route('admin.cores.index');
    }

    /**
     * Materiais escolhiveis. Os arquivados ficam de fora, excepto o que a cor
     * em edicao ja usa — senao o seletor abria vazio e uma gravacao inocente
     * mudava a cor de material.
     *
     * @return array<int, array{id: int, name: string, pricePerKgCents: int}>
     */
    private function materialOptions(?int $keepMaterialId = null): array
    {
        return Material::query()
            ->where(function (Builder $query) use ($keepMaterialId): void {
                $query->where('active', true);

                if ($keepMaterialId !== null) {
                    $query->orWhere('id', $keepMaterialId);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Material $material): array => [
                'id' => $material->id,
                'name' => $material->name,
                'pricePerKgCents' => $material->price_per_kg_cents,
            ])
            ->all();
    }
}
