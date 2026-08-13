<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Color\StoreColorRequest;
use App\Http\Requests\Color\UpdateColorRequest;
use App\Models\Color;
use App\Models\Material;
use App\Services\ColorService;
use App\Support\FilamentPalette;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection as Rows;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Na base de dados uma cor pertence a UM material: "PLA Preto" e "PETG Preto"
 * sao linhas distintas. Isso torna impossivel vender combinacoes que nao
 * existem e da swatches reais agrupados por material na montra.
 *
 * O backoffice mostra a camada de cima: "Terracota" e UMA cor, que existe em
 * varios materiais. A listagem agrupa as linhas pelo nome e o formulario grava
 * em leque (ColorService). Sem isto, mudar um hex obrigava a repetir a mesma
 * edicao material a material — e a paleta gere-se toda de uma vez.
 *
 * Consequencia nas rotas: o `{color}` do update/destroy/restore e o
 * REPRESENTANTE do grupo, nao o alvo. O servico resolve os irmaos pelo nome.
 */
class ColorController extends Controller
{
    public function __construct(
        private ColorService $colorService,
    ) {}

    public function index(): Response
    {
        /** @var Collection<int, Material> $materials */
        $materials = Material::query()
            ->with(['colors' => fn ($query) => $query
                ->withCount('variants')
                ->orderBy('sort_order')
                ->orderBy('name'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $colors = $this->flatten($materials);
        $groups = $this->groups($colors);

        return Inertia::render('admin/cores/index', [
            'colors' => $groups,
            // Uma so prop a servir dois consumidores: as chips do modal e a
            // vista "Por material". Sai dos materiais e nao das cores para um
            // material ainda sem cores nenhumas continuar a aparecer na grelha
            // — e um buraco a preencher, nao uma linha a esconder.
            'materials' => $materials->map(fn (Material $material): array => [
                'id' => $material->id,
                'name' => $material->name,
                'pricePerKgCents' => $material->price_per_kg_cents,
                'active' => $material->active,
                'colors' => $material->colors
                    ->filter(fn (Color $color): bool => $color->is_active)
                    ->map(fn (Color $color): array => [
                        'name' => $color->name,
                        'hex' => $color->hex_color,
                        // Null quando herda: a pastilha so mostra o preco
                        // quando ele difere do que o cartao ja diz no topo.
                        'ownPricePerKgCents' => $color->price_per_kg_cents,
                    ])
                    ->values()
                    ->all(),
            ]),
            'stats' => $this->stats($groups, $colors),
            'palette' => FilamentPalette::all(),
        ]);
    }

    public function store(StoreColorRequest $request): RedirectResponse
    {
        $this->colorService->storeGroup($request->validated());

        $this->toast('Cor criada.');

        return to_route('admin.cores.index');
    }

    public function update(UpdateColorRequest $request, Color $color): RedirectResponse
    {
        $this->colorService->updateGroup($color, $request->validated());

        $this->toast('Cor atualizada.');

        return to_route('admin.cores.index');
    }

    /**
     * "Apagar" no admin = arquivar (regra global de eliminacao logica).
     */
    public function destroy(Color $color): RedirectResponse
    {
        $this->colorService->archiveGroup($color);

        $this->toast('Cor arquivada.');

        return to_route('admin.cores.index');
    }

    public function restore(Color $color): RedirectResponse
    {
        $this->colorService->restoreGroup($color);

        $this->toast('Cor restaurada.');

        return back();
    }

    /**
     * Todas as cores numa lista so, com o material ja ligado.
     *
     * `setRelation` em vez de um segundo `Color::with('material')`: as linhas ja
     * vieram com os materiais, e repetir a consulta era carrega-las duas vezes
     * so para inverter o lado da relacao.
     *
     * @param  Collection<int, Material>  $materials
     * @return Rows<int, Color>
     */
    private function flatten(Collection $materials): Rows
    {
        /** @var Rows<int, Color> $colors */
        $colors = new Rows;

        foreach ($materials as $material) {
            foreach ($material->colors as $color) {
                $color->setRelation('material', $material);
                $colors->push($color);
            }
        }

        return $colors;
    }

    /**
     * As linhas agrupadas pelo nome — uma entrada por cor, como o admin a
     * pensa.
     *
     * @param  Rows<int, Color>  $colors
     * @return array<int, array<string, mixed>>
     */
    private function groups(Rows $colors): array
    {
        return $colors
            ->groupBy('name')
            ->map(function (Rows $rows, string $name): array {
                /** @var Rows<int, Color> $rows */
                $live = $rows->filter(fn (Color $color): bool => $color->is_active);
                $prices = $live->map(fn (Color $color): int => $color->effectivePricePerKgCents());
                $withOwnPrice = $live->filter(fn (Color $color): bool => $color->price_per_kg_cents !== null)->count();
                $ownPrices = $live->pluck('price_per_kg_cents')->unique();

                return [
                    // Representante do grupo: o id mais baixo, porque e o unico
                    // que nao muda quando se acrescenta ou tira um material.
                    'id' => (int) $rows->min('id'),
                    'name' => $name,
                    // O hex da linha mais acima na ordenacao. Se o mesmo nome
                    // tiver hex diferentes por material — possivel, o indice
                    // unico e por material —, gravar pelo modal alinha-os
                    // todos por este.
                    'hex' => (string) $rows->first()?->hex_color,
                    'materials' => $live
                        ->map(fn (Color $color): array => [
                            'id' => $color->material_id,
                            'name' => $color->material->name,
                            'pricePerKgCents' => $color->effectivePricePerKgCents(),
                        ])
                        ->values()
                        ->all(),
                    'materialIds' => $live->pluck('material_id')->values()->all(),
                    'minPricePerKgCents' => $prices->min(),
                    'maxPricePerKgCents' => $prices->max(),
                    'priceOrigin' => $this->priceOrigin($live->count(), $withOwnPrice),
                    /*
                     * O override so cabe num campo se for UM valor. Com precos
                     * proprios diferentes por material — ou uns proprios e
                     * outros herdados — vai null, e o modal abre o campo vazio
                     * a pedir o valor que vai uniformizar o grupo, em vez de
                     * escolher um por conta propria.
                     */
                    'ownPricePerKgCents' => $ownPrices->count() === 1
                        ? $ownPrices->first()
                        : null,
                    // Soma TODAS as linhas, arquivadas incluidas: a variante que
                    // usa uma cor arquivada continua a existir.
                    'variantsCount' => (int) $rows->sum('variants_count'),
                    'state' => $this->state($live),
                ];
            })
            // Arquivadas no fim: sao as unicas que nao pedem nada a ninguem.
            ->sortBy(fn (array $group): array => [
                $group['state'] === 'archived' ? 1 : 0,
                $group['name'],
            ])
            ->values()
            ->all();
    }

    /**
     * De onde vem o preco/kg do grupo. `mixed` e o caso que o desenho nao
     * previa mas os dados permitem: override nuns materiais e herdado noutros.
     */
    private function priceOrigin(int $live, int $withOwnPrice): string
    {
        if ($live === 0) {
            return 'none';
        }

        if ($withOwnPrice === 0) {
            return 'inherited';
        }

        return $withOwnPrice === $live ? 'own' : 'mixed';
    }

    /**
     * Estado apresentavel da cor.
     *
     * As cores nao tem stock — os materiais e que tem. Uma cor so entra em
     * alerta quando TODOS os materiais onde existe estao em falta: enquanto
     * houver um com bobines, a cor imprime-se. A regra do que e "em falta"
     * fica onde sempre esteve, no Material::isLowStock().
     *
     * @param  Rows<int, Color>  $live
     */
    private function state(Rows $live): string
    {
        if ($live->isEmpty()) {
            return 'archived';
        }

        return $live->every(fn (Color $color): bool => $color->material->isLowStock())
            ? 'low_stock'
            : 'active';
    }

    /**
     * Metricas do topo da listagem, sobre as coleccoes ja carregadas — mesma
     * escolha do MaterialController::stats, e pelo mesmo motivo: repetir as
     * regras num selectRaw era a segunda oportunidade de elas divergirem.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @param  Rows<int, Color>  $colors
     * @return array{activeCount: int, pairsCount: int, ownPriceCount: int, unusedCount: int}
     */
    private function stats(array $groups, Rows $colors): array
    {
        $live = array_filter($groups, fn (array $group): bool => $group['state'] !== 'archived');

        return [
            'activeCount' => count($live),
            // O numero que o desenho poe ao lado do anterior: quantas linhas
            // `colors` essas cores ocupam de facto.
            'pairsCount' => $colors->filter(fn (Color $color): bool => $color->is_active)->count(),
            'ownPriceCount' => count(array_filter(
                $live,
                fn (array $group): bool => $group['priceOrigin'] !== 'inherited',
            )),
            // Cores disponiveis que nenhuma variante usa — o numero acionavel.
            'unusedCount' => count(array_filter(
                $live,
                fn (array $group): bool => $group['variantsCount'] === 0,
            )),
        ];
    }
}
