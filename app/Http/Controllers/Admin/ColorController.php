<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Color\StoreColorRequest;
use App\Http\Requests\Color\UpdateColorRequest;
use App\Models\Color;
use App\Services\ColorService;
use App\Support\FilamentPalette;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A paleta: uma cor e um nome e um tom.
 *
 * Nao tem material — o mesmo "Preto" imprime-se em PLA, PETG ou TPU — e nao tem
 * preco: quem custa dinheiro e a bobine, e isso vive em /admin/materiais.
 *
 * Este controlador ja foi tres vezes maior. Enquanto uma cor pertenceu a um
 * material, a tabela guardava uma linha por par cor x material e era aqui que
 * elas se agrupavam pelo nome para o admin ver uma cor so. Com a ligacao fora,
 * o `{color}` das rotas passou a ser o alvo e nao o representante de nada.
 */
class ColorController extends Controller
{
    public function __construct(
        private ColorService $colorService,
    ) {}

    public function index(): Response
    {
        /** @var Collection<int, Color> $colors */
        $colors = Color::query()
            ->withCount('variants')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $rows = $colors
            ->map(fn (Color $color): array => [
                'id' => $color->id,
                'name' => $color->name,
                'hex' => $color->hex_color,
                'image' => $color->image,
                'sortOrder' => $color->sort_order,
                'variantsCount' => (int) $color->variants_count,
                'state' => $color->state(),
            ])
            // Arquivadas no fim: sao as unicas que nao pedem nada a ninguem.
            ->sortBy(fn (array $row): array => [
                $row['state'] === 'archived' ? 1 : 0,
                $row['sortOrder'],
                $row['name'],
            ])
            ->values()
            ->all();

        return Inertia::render('admin/cores/index', [
            'colors' => $rows,
            'stats' => $this->stats($rows),
            'palette' => FilamentPalette::all(),
        ]);
    }

    public function store(StoreColorRequest $request): RedirectResponse
    {
        $this->colorService->store($request->validated());

        $this->toast('Cor criada.');

        return to_route('admin.cores.index');
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

    public function restore(Color $color): RedirectResponse
    {
        $this->colorService->restore($color);

        $this->toast('Cor restaurada.');

        return back();
    }

    /**
     * Metricas do topo da listagem, sobre as linhas ja carregadas — mesma
     * escolha do MaterialController::stats, e pelo mesmo motivo: repetir as
     * regras num selectRaw era a segunda oportunidade de elas divergirem.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{activeCount: int, archivedCount: int, unusedCount: int}
     */
    private function stats(array $rows): array
    {
        $live = array_filter($rows, fn (array $row): bool => $row['state'] !== 'archived');

        return [
            'activeCount' => count($live),
            'archivedCount' => count($rows) - count($live),
            // Cores disponiveis que nenhuma variante usa — o numero acionavel.
            'unusedCount' => count(array_filter(
                $live,
                fn (array $row): bool => $row['variantsCount'] === 0,
            )),
        ];
    }
}
