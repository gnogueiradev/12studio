<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tag\StoreTagRequest;
use App\Http\Requests\Tag\UpdateTagRequest;
use App\Models\Tag;
use App\Services\TagService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * O segundo eixo de organizacao, ao lado das categorias.
 *
 * A diferenca entre os dois esta na cardinalidade e no ambito: um produto tem
 * UMA categoria e varias etiquetas, e uma etiqueta serve tambem o cliente e a
 * encomenda — que categorias nao servem.
 *
 * Esta pagina existe por uma razao so: ate aqui uma etiqueta nascia ao gravar um
 * produto e mais nada. Escrever "natl" uma vez era ficar com "natl" para sempre,
 * a sugerir-se ao lado de "natal".
 */
class TagController extends Controller
{
    public function __construct(
        private TagService $tagService,
    ) {}

    public function index(): Response
    {
        $rows = $this->tagService->listing();

        return Inertia::render('admin/etiquetas/index', [
            'tags' => $rows,
            'stats' => $this->stats($rows),
        ]);
    }

    public function store(StoreTagRequest $request): RedirectResponse
    {
        $tag = $this->tagService->store($request->validated());

        $this->toast($tag->wasRecentlyCreated
            ? 'Etiqueta criada.'
            : 'Essa etiqueta já existia neste âmbito.');

        return to_route('admin.etiquetas.index');
    }

    public function update(UpdateTagRequest $request, Tag $tag): RedirectResponse
    {
        $plan = $this->tagService->update($tag, $request->validated());

        $this->toast($plan->isMerge()
            ? "Etiquetas fundidas em «{$plan->name}»."
            : 'Etiqueta atualizada.');

        return to_route('admin.etiquetas.index');
    }

    /**
     * Apagar mesmo — a excepcao consciente a regra global de eliminacao logica,
     * escrita na migracao original. Os pivots caem por cascade, portanto quem
     * usava a etiqueta fica sem ela e nada mais.
     */
    public function destroy(Tag $tag): RedirectResponse
    {
        $this->tagService->destroy($tag);

        $this->toast('Etiqueta apagada.');

        return to_route('admin.etiquetas.index');
    }

    public function prune(): RedirectResponse
    {
        $deleted = $this->tagService->pruneUnused();

        $this->toast($deleted === 0
            ? 'Não havia etiquetas por usar.'
            : ($deleted === 1 ? '1 etiqueta apagada.' : "{$deleted} etiquetas apagadas."));

        return to_route('admin.etiquetas.index');
    }

    /**
     * Metricas do topo, sobre as linhas ja carregadas — mesma escolha do
     * ColorController::stats, e pelo mesmo motivo: repetir as regras num
     * selectRaw era a segunda oportunidade de elas divergirem.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{total: int, byScope: array<string, int>, unusedCount: int}
     */
    private function stats(array $rows): array
    {
        $byScope = [];

        foreach (Tag::SCOPES as $scope) {
            $byScope[$scope] = count(array_filter(
                $rows,
                fn (array $row): bool => $row['scope'] === $scope,
            ));
        }

        return [
            'total' => count($rows),
            'byScope' => $byScope,
            // O numero acionavel: e o que o botao de limpar vai apagar.
            'unusedCount' => count(array_filter(
                $rows,
                fn (array $row): bool => $row['usageCount'] === 0,
            )),
        ];
    }
}
