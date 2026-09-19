<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;

/**
 * Paginacao das listagens do MCP. Maximo de 50 por pagina: um "lista tudo"
 * nao pode virar um despejo da BD nem uma resposta que o modelo nao cabe.
 */
trait Paginates
{
    protected const PER_PAGE_DEFAULT = 20;

    protected const PER_PAGE_MAX = 50;

    /**
     * @return array<string, mixed>
     */
    protected function paginationSchema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1)->description('Página (começa em 1).'),
            'per_page' => $schema->integer()->min(1)->max(self::PER_PAGE_MAX)
                ->description('Resultados por página (máximo '.self::PER_PAGE_MAX.').'),
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    protected function paginate(Builder $query, Request $request): LengthAwarePaginator
    {
        $perPage = min(self::PER_PAGE_MAX, max(1, (int) ($request->get('per_page') ?? self::PER_PAGE_DEFAULT)));
        $page = max(1, (int) ($request->get('page') ?? 1));

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * @param  LengthAwarePaginator<int, covariant Model>  $paginator
     * @return array{page: int, per_page: int, total: int, last_page: int}
     */
    protected function pageMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
