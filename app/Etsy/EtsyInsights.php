<?php

namespace App\Etsy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Olhar para a Etsy sem mexer em nada: o que os outros vendem, que lojas ha,
 * o que os compradores dizem, e que categorias existem.
 *
 * So leitura. O que vem de outras pessoas (titulos de outros vendedores,
 * texto de avaliacoes) e tratado como dados por quem o apresenta.
 */
class EtsyInsights
{
    private const TAXONOMY_CACHE_KEY = 'etsy:taxonomy:seller';

    /** As categorias mudam poucas vezes por ano. */
    private const TAXONOMY_CACHE_DAYS = 7;

    public function __construct(
        private EtsyApi $api,
        private EtsyTokens $tokens,
    ) {}

    /**
     * Pesquisa nos anuncios ativos de toda a Etsy.
     *
     * @param  array<string, mixed>  $filters  keywords, min_price, max_price, taxonomy_id, sort_on, sort_order, shop_location
     * @return array{count: int, results: array<int, array<array-key, mixed>>}
     */
    public function searchListings(array $filters, int $limit, int $offset): array
    {
        $response = $this->api->activeListings([...$filters, 'limit' => $limit, 'offset' => $offset]);

        return $this->page($response);
    }

    /**
     * @return array{count: int, results: array<int, array<array-key, mixed>>}
     */
    public function findShops(string $name, int $limit, int $offset): array
    {
        return $this->page($this->api->findShops($name, $limit, $offset));
    }

    /**
     * As avaliacoes de um anuncio, ou — sem anuncio — as da loja ligada.
     *
     * @param  array<string, mixed>  $filters  min_created, max_created
     * @return array{count: int, results: array<int, array<array-key, mixed>>}
     */
    public function reviews(?int $listingId, array $filters, int $limit, int $offset): array
    {
        $query = [...$filters, 'limit' => $limit, 'offset' => $offset];

        if ($listingId !== null) {
            return $this->page($this->api->listingReviews($listingId, $query));
        }

        $connection = $this->tokens->connection() ?? throw EtsyException::notConnected();

        return $this->page($this->api->shopReviews($connection->shop_id, $query));
    }

    /**
     * Categorias cujo nome (ou caminho) contem o texto. A arvore inteira sao
     * milhares de nos: procura-se, nao se despeja.
     *
     * @return array<int, array{id: int, name: string, path: string, level: int, is_leaf: bool}>
     */
    public function searchTaxonomy(string $query, int $max = 25): array
    {
        $needle = Str::lower(Str::ascii(trim($query)));

        if ($needle === '') {
            return [];
        }

        $matches = array_values(array_filter(
            $this->taxonomy(),
            fn (array $node): bool => str_contains(Str::lower(Str::ascii($node['path'])), $needle),
        ));

        // Primeiro as que tem o texto no proprio nome, e dessas as mais
        // curtas: "Vases" antes de "Home & Living > Vases > Bud Vases".
        usort($matches, fn (array $a, array $b): int => [
            ! str_contains(Str::lower(Str::ascii($a['name'])), $needle),
            mb_strlen($a['path']),
        ] <=> [
            ! str_contains(Str::lower(Str::ascii($b['name'])), $needle),
            mb_strlen($b['path']),
        ]);

        return array_slice($matches, 0, $max);
    }

    /**
     * O que se pode dizer de um anuncio desta categoria: os atributos, e
     * quais deles podem ser variacoes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function taxonomyProperties(int $taxonomyId): array
    {
        $response = $this->api->taxonomyProperties($taxonomyId);

        return array_map(fn (array $property): array => [
            'property_id' => $property['property_id'] ?? null,
            'name' => $property['display_name'] ?? $property['name'] ?? null,
            'is_required' => $property['is_required'] ?? null,
            'supports_attributes' => $property['supports_attributes'] ?? null,
            'supports_variations' => $property['supports_variations'] ?? null,
            'is_multivalued' => $property['is_multivalued'] ?? null,
            'scales' => array_map(fn (array $scale): array => [
                'scale_id' => $scale['scale_id'] ?? null,
                'name' => $scale['display_name'] ?? null,
            ], EtsyPresenter::rows($property['scales'] ?? null)),
            'possible_values' => array_map(fn (array $value): array => [
                'value_id' => $value['value_id'] ?? null,
                'name' => $value['name'] ?? null,
            ], EtsyPresenter::rows($property['possible_values'] ?? null)),
        ], EtsyPresenter::rows($response['results'] ?? null));
    }

    /**
     * A arvore de categorias, achatada e em cache.
     *
     * @return array<int, array{id: int, name: string, path: string, level: int, is_leaf: bool}>
     */
    private function taxonomy(): array
    {
        /** @var array<int, array{id: int, name: string, path: string, level: int, is_leaf: bool}> $flat */
        $flat = Cache::remember(
            self::TAXONOMY_CACHE_KEY,
            now()->addDays(self::TAXONOMY_CACHE_DAYS),
            fn (): array => $this->flatten(EtsyPresenter::rows($this->api->sellerTaxonomy()['results'] ?? null), ''),
        );

        return $flat;
    }

    /**
     * @param  array<int, array<array-key, mixed>>  $nodes
     * @return array<int, array{id: int, name: string, path: string, level: int, is_leaf: bool}>
     */
    private function flatten(array $nodes, string $parentPath): array
    {
        $flat = [];

        foreach ($nodes as $node) {
            if (! is_numeric($node['id'] ?? null) || ! is_string($node['name'] ?? null)) {
                continue;
            }

            $path = $parentPath === '' ? $node['name'] : "{$parentPath} > {$node['name']}";
            $children = EtsyPresenter::rows($node['children'] ?? null);

            $flat[] = [
                'id' => (int) $node['id'],
                'name' => $node['name'],
                'path' => $path,
                'level' => (int) ($node['level'] ?? 0),
                'is_leaf' => $children === [],
            ];

            array_push($flat, ...$this->flatten($children, $path));
        }

        return $flat;
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return array{count: int, results: array<int, array<array-key, mixed>>}
     */
    private function page(array $response): array
    {
        $results = EtsyPresenter::rows($response['results'] ?? null);

        return ['count' => (int) ($response['count'] ?? count($results)), 'results' => $results];
    }
}
