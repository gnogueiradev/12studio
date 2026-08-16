<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Color\StoreColorRequest;
use App\Http\Requests\Color\UpdateColorRequest;
use App\Models\Color;
use App\Services\ColorService;
use App\Support\FilamentPalette;
use App\Support\MaterialOptions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A paleta: uma cor e um nome, um tom, e os filamentos em que existe.
 *
 * Nao tem preco: quem custa dinheiro e a bobine, e isso vive em
 * /admin/materiais. Tem filamentos, mas em N:N — e o que impede a matriz de
 * criacao de produtos de inventar um "Rosa Silk" que nao ha como imprimir.
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
            // Carregada e nao contada: a linha mostra os nomes dos filamentos, e
            // o `state()` le desta relacao em vez de perguntar por cor (N+1).
            ->with('materials:id,name')
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
                'materials' => $color->materials
                    ->map(fn ($material): array => ['id' => $material->id, 'name' => $material->name])
                    ->values()
                    ->all(),
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
            // As bobines que se podem marcar no formulario da cor. Traz as
            // arquivadas que alguma cor ainda declara, senao gravar uma cor
            // desmarcava-as em silencio — e escondia as variantes com elas.
            'materials' => MaterialOptions::all($this->declaredMaterialIds($colors)),
        ]);
    }

    public function store(StoreColorRequest $request): RedirectResponse
    {
        $color = $this->colorService->store($request->validated());

        $this->toast('Cor criada.'.$this->materialsNote($request->materialIds(), $color));

        return to_route('admin.cores.index');
    }

    public function update(UpdateColorRequest $request, Color $color): RedirectResponse
    {
        $this->colorService->update($color, $request->validated());

        $this->toast('Cor atualizada.'.$this->materialsNote($request->materialIds(), $color));

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
     * Todos os materiais que alguma cor declara, arquivados incluidos.
     *
     * Mesmo principio do `$keep` do MaterialOptions: um material arquivado que
     * o rosa ainda declara tem de continuar a aparecer marcado no formulario.
     * Sem isso a chip nao vinha, o `material_ids` submetido saia sem ele, e uma
     * gravacao inocente escondia as variantes que o usam.
     *
     * @param  Collection<int, Color>  $colors
     * @return array<int, int>
     */
    private function declaredMaterialIds(Collection $colors): array
    {
        return $colors
            ->flatMap(fn (Color $color): array => $color->materials->pluck('id')->all())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Sincroniza os filamentos da cor e devolve o que ha a acrescentar ao aviso.
     *
     * Gravar uma cor pode fazer variantes desaparecerem da loja — desmarcar o
     * Silk esconde tudo o que era silk nessa cor. E uma consequencia a dois
     * cliques de distancia da accao, e por isso diz-se em voz alta, com o
     * numero. Nao ha nada a dizer quando nao houve efeito nenhum.
     *
     * `null` significa que o formulario nao falou de materiais: nao se toca na
     * pivo (ver StoreColorRequest::materialIds).
     *
     * @param  array<int, int>|null  $materialIds
     */
    private function materialsNote(?array $materialIds, Color $color): string
    {
        if ($materialIds === null) {
            return '';
        }

        ['hidden' => $hidden, 'restored' => $restored] = $this->colorService->syncMaterials($color, $materialIds);

        $notes = [];

        if ($hidden > 0) {
            $notes[] = $hidden === 1
                ? ' Escondi 1 variação que já não consegues imprimir.'
                : " Escondi {$hidden} variações que já não consegues imprimir.";
        }

        if ($restored > 0) {
            $notes[] = $restored === 1
                ? ' Voltou 1 variação que estava escondida.'
                : " Voltaram {$restored} variações que estavam escondidas.";
        }

        return implode('', $notes);
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
