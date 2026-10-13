<?php

namespace App\Mcp\Tools\Etsy\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

/**
 * Paginacao das listas que vem da Etsy. La e `limit`/`offset`; para o Claude
 * e `page`/`per_page`, como nas listas do 12studio.
 */
trait PagesEtsy
{
    protected const PER_PAGE_DEFAULT = 20;

    protected const PER_PAGE_MAX = 50;

    /**
     * @return array<string, mixed>
     */
    protected function pageSchema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1)->description('Página (começa em 1).'),
            'per_page' => $schema->integer()->min(1)->max(self::PER_PAGE_MAX)
                ->description('Resultados por página (máximo '.self::PER_PAGE_MAX.').'),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function pageRules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::PER_PAGE_MAX],
        ];
    }

    /**
     * @return array{page: int, per_page: int, limit: int, offset: int}
     */
    protected function pageOf(Request $request): array
    {
        $perPage = min(self::PER_PAGE_MAX, max(1, (int) ($request->get('per_page') ?? self::PER_PAGE_DEFAULT)));
        $page = max(1, (int) ($request->get('page') ?? 1));

        return ['page' => $page, 'per_page' => $perPage, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage];
    }

    /**
     * @param  array{page: int, per_page: int, limit: int, offset: int}  $page
     * @return array{page: int, per_page: int, total: int, last_page: int}
     */
    protected function pageMetaOf(array $page, int $total): array
    {
        return [
            'page' => $page['page'],
            'per_page' => $page['per_page'],
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $page['per_page'])),
        ];
    }
}
