<?php

namespace App\Etsy;

/**
 * Os endpoints da Etsy, um metodo fino por cada.
 *
 * Nao decide nada: so sabe o caminho, o verbo e a codificacao de cada
 * operacao (os nomes entre parenteses sao os `operationId` da especificacao
 * oficial). Quem decide o que mandar sao os servicos por cima.
 */
class EtsyApi
{
    public function __construct(
        private EtsyClient $client,
    ) {}

    /**
     * A conta ligada: `user_id` e `shop_id` (getMe).
     *
     * @return array<array-key, mixed>
     */
    public function me(): array
    {
        return $this->client->get('/users/me');
    }

    /**
     * Uma loja pelo id (getShop). Leitura publica.
     *
     * @return array<array-key, mixed>
     */
    public function shop(int $shopId): array
    {
        return $this->client->get("/shops/{$shopId}", public: true);
    }

    /**
     * Um utilizador pelo id (getUser).
     *
     * @return array<array-key, mixed>
     */
    public function user(int $userId): array
    {
        return $this->client->get("/users/{$userId}");
    }

    /**
     * As moradas da conta ligada (getUserAddresses) — nao ha como ler as de
     * outro utilizador.
     *
     * @return array<array-key, mixed>
     */
    public function userAddresses(): array
    {
        return $this->client->get('/user/addresses', ['limit' => 100]);
    }

    // ── Anuncios ───────────────────────────────────────────────────────────

    /**
     * Os anuncios de uma loja, por estado (getListingsByShop). Com o token:
     * rascunhos e inativos so os ve o dono.
     *
     * @param  array<string, mixed>  $query  state, limit, offset, sort_on, sort_order, includes
     * @return array<array-key, mixed>
     */
    public function shopListings(int $shopId, array $query = []): array
    {
        return $this->client->get("/shops/{$shopId}/listings", $query);
    }

    /**
     * Um anuncio (getListing). Com o token, pela mesma razao: sem ele um
     * rascunho da propria loja vinha como "nao encontrado".
     *
     * @param  array<int, string>  $includes  Images, Inventory, Videos, Shop, Shipping…
     * @return array<array-key, mixed>
     */
    public function listing(int $listingId, array $includes = []): array
    {
        return $this->client->get("/listings/{$listingId}", $includes === [] ? [] : ['includes' => $includes]);
    }

    /**
     * Pesquisa em todos os anuncios ativos da Etsy (findAllListingsActive).
     * Leitura publica.
     *
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     */
    public function activeListings(array $query): array
    {
        return $this->client->get('/listings/active', $query, public: true);
    }

    // ── Lojas, avaliacoes, categorias (leituras publicas) ──────────────────

    /**
     * Lojas por nome (findShops).
     *
     * @return array<array-key, mixed>
     */
    public function findShops(string $name, int $limit, int $offset): array
    {
        return $this->client->get('/shops', ['shop_name' => $name, 'limit' => $limit, 'offset' => $offset], public: true);
    }

    /**
     * @param  array<string, mixed>  $query  limit, offset, min_created, max_created
     * @return array<array-key, mixed>
     */
    public function shopReviews(int $shopId, array $query = []): array
    {
        return $this->client->get("/shops/{$shopId}/reviews", $query, public: true);
    }

    /**
     * @param  array<string, mixed>  $query  limit, offset, min_created, max_created
     * @return array<array-key, mixed>
     */
    public function listingReviews(int $listingId, array $query = []): array
    {
        return $this->client->get("/listings/{$listingId}/reviews", $query, public: true);
    }

    /**
     * A arvore de categorias de quem vende (getSellerTaxonomyNodes) — a que
     * os anuncios usam. Sao milhares de nos: quem chama guarda-a em cache.
     *
     * @return array<array-key, mixed>
     */
    public function sellerTaxonomy(): array
    {
        return $this->client->get('/seller-taxonomy/nodes', public: true);
    }

    /**
     * As propriedades de uma categoria (getPropertiesByTaxonomyId): o que se
     * pode dizer de um anuncio dela, e o que pode ser variacao.
     *
     * @return array<array-key, mixed>
     */
    public function taxonomyProperties(int $taxonomyId): array
    {
        return $this->client->get("/seller-taxonomy/nodes/{$taxonomyId}/properties", public: true);
    }
}
