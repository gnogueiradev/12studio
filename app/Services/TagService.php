<?php

namespace App\Services;

use App\Models\Tag;

class TagService
{
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
}
