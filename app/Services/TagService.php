<?php

namespace App\Services;

use App\Models\Tag;
use App\Support\TagMergePlan;
use Illuminate\Support\Facades\DB;

class TagService
{
    /**
     * Os tres pivots, por tabela e coluna do dono. O `scope` de uma etiqueta ja
     * diz qual deles e o seu, mas quem conta usos e quem funde percorre os tres:
     * sao duas queries a mais e uma classe inteira de bugs a menos se um dia uma
     * etiqueta mudar de ambito por engano.
     */
    private const PIVOTS = [
        'product_tag' => 'product_id',
        'tag_user' => 'user_id',
        'order_tag' => 'order_id',
    ];

    /**
     * Nomes escritos a mao -> ids do vocabulario de um ambito, criando as
     * etiquetas que ainda nao existem. A chave e o slug: "Natal", "natal" e
     * "NATAL" sao a mesma etiqueta.
     *
     * Veio do ProductService, onde nasceu preso ao catalogo. O que mudou foi so
     * o `scope` entrar na chave do firstOrCreate — "natal" de produto e "natal"
     * de cliente sao duas linhas, e e isso que impede as sugestoes de uma ficha
     * de cliente de se encherem de vocabulario da loja.
     *
     * @param  array<int, string>  $names
     * @return array<int, int>
     */
    public function idsFor(string $scope, array $names): array
    {
        return collect($names)
            ->map(fn (string $name): string => trim($name))
            ->filter()
            // Desambigua ANTES de ir a BD: "Natal" e "natal" dariam o mesmo
            // slug e o firstOrCreate devolveria a mesma linha duas vezes.
            ->keyBy(fn (string $name): string => str($name)->slug()->value())
            ->reject(fn (string $name, string $slug): bool => $slug === '')
            ->map(fn (string $name, string $slug): int => Tag::query()->firstOrCreate(
                ['scope' => $scope, 'slug' => $slug],
                ['name' => $name],
            )->id)
            ->values()
            ->all();
    }

    /**
     * Sugestoes para o campo de etiquetas de um ambito. Todas, incluindo as que
     * ainda nao tem uso: uma etiqueta criada de proposito na pagina de gestao
     * tem de aparecer antes de alguem a usar pela primeira vez.
     *
     * @return array<int, string>
     */
    public function suggestions(string $scope): array
    {
        return Tag::query()
            ->inScope($scope)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * Opcoes do filtro por etiqueta de uma listagem: so as que tem pelo menos um
     * uso. Ao contrario das sugestoes, aqui uma etiqueta sem uso e uma opcao que
     * so pode dar zero resultados.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function optionsFor(string $scope): array
    {
        $relation = match ($scope) {
            Tag::SCOPE_CUSTOMER => 'customers',
            Tag::SCOPE_ORDER => 'orders',
            default => 'products',
        };

        return Tag::query()
            ->inScope($scope)
            ->has($relation)
            ->orderBy('name')
            ->get(['slug', 'name'])
            // O valor e o slug e nao o id: mantem o `?tag=natal` do URL legivel
            // e estavel se a etiqueta for renomeada sem mudar de slug.
            ->map(fn (Tag $tag): array => ['value' => $tag->slug, 'label' => $tag->name])
            ->all();
    }

    /**
     * Criar da pagina de gestao. Um nome que ja exista no ambito devolve a
     * etiqueta que la esta em vez de rebentar: e o mesmo pedido, feito duas
     * vezes. Quem chama distingue os dois casos pelo `wasRecentlyCreated`.
     *
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): Tag
    {
        $name = trim((string) $data['name']);

        return Tag::query()->firstOrCreate(
            ['scope' => $data['scope'], 'slug' => str($name)->slug()->value()],
            ['name' => $name],
        );
    }

    /**
     * Renomear — ou fundir, quando o nome novo ja pertence a outra etiqueta do
     * mesmo ambito. O ambito nunca muda: move-la deixava os usos existentes a
     * apontar para o pivot errado, e nao ha resposta certa para o que fazer com
     * eles. Para mover, cria-se no ambito certo.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Tag $tag, array $data): TagMergePlan
    {
        $plan = TagMergePlan::for(
            $tag->id,
            (string) $data['name'],
            Tag::query()->inScope($tag->scope)->pluck('id', 'slug')->all(),
        );

        DB::transaction(function () use ($tag, $plan): void {
            if (! $plan->isMerge()) {
                $tag->update(['name' => $plan->name, 'slug' => $plan->slug]);

                return;
            }

            $this->repoint($tag->id, (int) $plan->mergeInto);

            // O delete leva o que sobrou dos pivots por cascade — as linhas que
            // o insertOrIgnore nao copiou porque o destino ja as tinha.
            $tag->delete();
        });

        return $plan;
    }

    /**
     * Apagar mesmo, e nao arquivar: e a excepcao consciente a regra global de
     * eliminacao logica, ja escrita na migracao original. Uma etiqueta nao e
     * historial — nenhuma encomenda a referencia no valor que cobrou — e uma
     * etiqueta arquivada seria so uma sugestao que ninguem volta a ver.
     */
    public function destroy(Tag $tag): void
    {
        $tag->delete();
    }

    /**
     * Limpa as etiquetas que nenhum produto, cliente ou encomenda usa. Cumpre a
     * promessa da migracao original ("uma tag sem produtos e ruido") sem o
     * efeito lateral que ela implicava: aqui e o admin que decide quando, e nao
     * o acto de desmarcar o ultimo uso.
     *
     * @return int quantas foram apagadas
     */
    public function pruneUnused(): int
    {
        $query = Tag::query();

        foreach (array_keys(self::PIVOTS) as $pivot) {
            $query->whereNotExists(
                fn ($sub) => $sub->from($pivot)->whereColumn('tag_id', 'tags.id'),
            );
        }

        return $query->delete();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listing(): array
    {
        return Tag::query()
            ->withCount(['products', 'customers', 'orders'])
            ->orderBy('scope')
            ->orderBy('name')
            ->get()
            ->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'scope' => $tag->scope,
                'name' => $tag->name,
                'slug' => $tag->slug,
                // Uma so contagem: a etiqueta vive num ambito, portanto duas das
                // tres sao sempre zero. Quem le a tabela quer saber "quantos
                // perdem isto se eu apagar", nao a decomposicao.
                'usageCount' => (int) $tag->products_count
                    + (int) $tag->customers_count
                    + (int) $tag->orders_count,
            ])
            ->all();
    }

    /**
     * Reaponta os pivots de uma etiqueta para outra. O insertOrIgnore encosta-se
     * a primary composta de cada pivot: um produto que ja tenha as duas
     * etiquetas nao pode ficar com a linha repetida.
     */
    private function repoint(int $from, int $into): void
    {
        foreach (self::PIVOTS as $pivot => $ownerColumn) {
            $owners = DB::table($pivot)->where('tag_id', $from)->pluck($ownerColumn);

            if ($owners->isEmpty()) {
                continue;
            }

            DB::table($pivot)->insertOrIgnore(
                $owners->map(fn (int $owner): array => [
                    $ownerColumn => $owner,
                    'tag_id' => $into,
                ])->all(),
            );
        }
    }
}
