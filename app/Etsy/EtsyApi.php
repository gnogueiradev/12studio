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

    /**
     * Muda campos de um anuncio (updateListing). So o que vier em $data.
     *
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function updateListing(int $shopId, int $listingId, array $data): array
    {
        return $this->client->patchForm("/shops/{$shopId}/listings/{$listingId}", $data);
    }

    // ── A loja: textos e seccoes ───────────────────────────────────────────

    /**
     * Os textos publicos da loja (updateShop): title, announcement,
     * sale_message, digital_sale_message, policy_additional.
     *
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function updateShop(int $shopId, array $data): array
    {
        return $this->client->putForm("/shops/{$shopId}", $data);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function sections(int $shopId): array
    {
        return $this->client->get("/shops/{$shopId}/sections", public: true);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function createSection(int $shopId, string $title): array
    {
        return $this->client->postForm("/shops/{$shopId}/sections", ['title' => $title]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function updateSection(int $shopId, int $sectionId, string $title): array
    {
        return $this->client->putForm("/shops/{$shopId}/sections/{$sectionId}", ['title' => $title]);
    }

    public function deleteSection(int $shopId, int $sectionId): void
    {
        $this->client->delete("/shops/{$shopId}/sections/{$sectionId}");
    }

    // ── Envios: perfis, destinos, upgrades ─────────────────────────────────

    /**
     * Os perfis de envio da loja, cada um ja com os seus destinos e upgrades
     * (getShopShippingProfiles).
     *
     * @return array<array-key, mixed>
     */
    public function shippingProfiles(int $shopId): array
    {
        return $this->client->get("/shops/{$shopId}/shipping-profiles");
    }

    /**
     * Um perfil novo nasce com o primeiro destino: titulo, origem, custos e
     * prazo (createShopShippingProfile).
     *
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function createShippingProfile(int $shopId, array $data): array
    {
        return $this->client->postForm("/shops/{$shopId}/shipping-profiles", $data);
    }

    /**
     * So titulo, origem e codigo postal (updateShopShippingProfile): os
     * custos vivem nos destinos.
     *
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function updateShippingProfile(int $shopId, int $profileId, array $data): array
    {
        return $this->client->putForm("/shops/{$shopId}/shipping-profiles/{$profileId}", $data);
    }

    public function deleteShippingProfile(int $shopId, int $profileId): void
    {
        $this->client->delete("/shops/{$shopId}/shipping-profiles/{$profileId}");
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function createShippingDestination(int $shopId, int $profileId, array $data): array
    {
        return $this->client->postForm("/shops/{$shopId}/shipping-profiles/{$profileId}/destinations", $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function updateShippingDestination(int $shopId, int $profileId, int $destinationId, array $data): array
    {
        return $this->client->putForm("/shops/{$shopId}/shipping-profiles/{$profileId}/destinations/{$destinationId}", $data);
    }

    public function deleteShippingDestination(int $shopId, int $profileId, int $destinationId): void
    {
        $this->client->delete("/shops/{$shopId}/shipping-profiles/{$profileId}/destinations/{$destinationId}");
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function createShippingUpgrade(int $shopId, int $profileId, array $data): array
    {
        return $this->client->postForm("/shops/{$shopId}/shipping-profiles/{$profileId}/upgrades", $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function updateShippingUpgrade(int $shopId, int $profileId, int $upgradeId, array $data): array
    {
        return $this->client->putForm("/shops/{$shopId}/shipping-profiles/{$profileId}/upgrades/{$upgradeId}", $data);
    }

    public function deleteShippingUpgrade(int $shopId, int $profileId, int $upgradeId): void
    {
        $this->client->delete("/shops/{$shopId}/shipping-profiles/{$profileId}/upgrades/{$upgradeId}");
    }

    /**
     * As transportadoras que a Etsy conhece para um pais de origem, com as
     * classes de correio de cada uma (getShippingCarriers). Leitura publica.
     *
     * @return array<array-key, mixed>
     */
    public function shippingCarriers(string $originCountryIso): array
    {
        return $this->client->get('/shipping-carriers', ['origin_country_iso' => $originCountryIso], public: true);
    }

    // ── Perfis de processamento e politicas de devolucao ───────────────────

    /**
     * Os perfis de processamento (getShopReadinessStateDefinitions): pronto
     * a enviar ou feito por encomenda, e em quanto tempo.
     *
     * @return array<array-key, mixed>
     */
    public function processingProfiles(int $shopId): array
    {
        return $this->client->get("/shops/{$shopId}/readiness-state-definitions", ['limit' => 100]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function createProcessingProfile(int $shopId, array $data): array
    {
        return $this->client->postForm("/shops/{$shopId}/readiness-state-definitions", $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function updateProcessingProfile(int $shopId, int $definitionId, array $data): array
    {
        return $this->client->putForm("/shops/{$shopId}/readiness-state-definitions/{$definitionId}", $data);
    }

    public function deleteProcessingProfile(int $shopId, int $definitionId): void
    {
        $this->client->delete("/shops/{$shopId}/readiness-state-definitions/{$definitionId}");
    }

    /**
     * @return array<array-key, mixed>
     */
    public function returnPolicies(int $shopId): array
    {
        return $this->client->get("/shops/{$shopId}/policies/return", public: true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function createReturnPolicy(int $shopId, array $data): array
    {
        return $this->client->postForm("/shops/{$shopId}/policies/return", $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function updateReturnPolicy(int $shopId, int $policyId, array $data): array
    {
        return $this->client->putForm("/shops/{$shopId}/policies/return/{$policyId}", $data);
    }

    public function deleteReturnPolicy(int $shopId, int $policyId): void
    {
        $this->client->delete("/shops/{$shopId}/policies/return/{$policyId}");
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
