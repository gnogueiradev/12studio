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
     * O inventario nao vem por aqui: o getListing so aceita Images, Shop,
     * User, Translations, Videos, Personalization e BuyerPrice nos
     * `includes`, e a qualquer outro responde 400. E o listingInventory().
     *
     * @param  array<int, string>  $includes  Images, Videos, Shop…
     * @return array<array-key, mixed>
     */
    public function listing(int $listingId, array $includes = []): array
    {
        return $this->client->get("/listings/{$listingId}", $includes === [] ? [] : ['includes' => $includes]);
    }

    /**
     * O inventario de um anuncio (getListingInventory): cada variacao com a
     * referencia, as propriedades e a oferta (preco, quantidade). A mesma
     * forma que o updateListingInventory recebe e devolve.
     *
     * @return array<array-key, mixed>
     */
    public function listingInventory(int $listingId): array
    {
        return $this->client->get("/listings/{$listingId}/inventory");
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

    /**
     * Varios anuncios de uma vez, ate 100 (getListingsByListingIds). Para
     * conferir os estados sem um pedido por anuncio.
     *
     * @param  array<int, int>  $listingIds
     * @return array<array-key, mixed>
     */
    public function listingsByIds(array $listingIds): array
    {
        return $this->client->get('/listings/batch', ['listing_ids' => $listingIds]);
    }

    /**
     * Apaga um anuncio (deleteListing). Nao se recupera. A Etsy so deixa em
     * certos estados (rascunho, inativo, esgotado, expirado, ou ativo e
     * disponivel); fora deles responde 409.
     */
    public function deleteListing(int $listingId): void
    {
        $this->client->delete("/listings/{$listingId}");
    }

    /**
     * Cria um anuncio, sempre em rascunho (createDraftListing). Quem chama
     * NAO repete isto sozinho: um rascunho criado duas vezes sao dois
     * anuncios.
     *
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function createDraftListing(int $shopId, array $data): array
    {
        return $this->client->postForm("/shops/{$shopId}/listings", $data);
    }

    /**
     * O inventario inteiro de um anuncio (updateListingInventory): cada
     * variacao com a referencia, o preco, a quantidade e o perfil de
     * processamento. Substitui o que la esta. Em JSON, ao contrario do resto.
     *
     * @param  array<string, mixed>  $inventory
     * @param  array<string, mixed>  $query  max_variations_supported, quando sao tres.
     * @return array<array-key, mixed>
     */
    public function updateListingInventory(int $listingId, array $inventory, array $query = []): array
    {
        return $this->client->putJson("/listings/{$listingId}/inventory", $inventory, $query);
    }

    /**
     * Um atributo da categoria no anuncio (updateListingProperty).
     *
     * @param  array<string, mixed>  $data  value_ids, values, scale_id
     * @return array<array-key, mixed>
     */
    public function updateListingProperty(int $shopId, int $listingId, int $propertyId, array $data): array
    {
        return $this->client->putForm("/shops/{$shopId}/listings/{$listingId}/properties/{$propertyId}", $data);
    }

    /**
     * Carrega uma fotografia para um anuncio (uploadListingImage).
     *
     * @param  resource|string  $contents
     * @param  array<string, mixed>  $fields  rank, alt_text, overwrite
     * @return array<array-key, mixed>
     */
    public function uploadListingImage(int $shopId, int $listingId, mixed $contents, string $filename, array $fields = []): array
    {
        return $this->client->postMultipart("/shops/{$shopId}/listings/{$listingId}/images", 'image', $contents, $filename, $fields);
    }

    public function deleteListingImage(int $shopId, int $listingId, int $imageId): void
    {
        $this->client->delete("/shops/{$shopId}/listings/{$listingId}/images/{$imageId}");
    }

    /**
     * Carrega o video de um anuncio (uploadListingVideo). Um por anuncio.
     *
     * @param  resource|string  $contents
     * @return array<array-key, mixed>
     */
    public function uploadListingVideo(int $shopId, int $listingId, mixed $contents, string $filename): array
    {
        return $this->client->postMultipart("/shops/{$shopId}/listings/{$listingId}/videos", 'video', $contents, $filename, ['name' => $filename]);
    }

    public function deleteListingVideo(int $shopId, int $listingId, int $videoId): void
    {
        $this->client->delete("/shops/{$shopId}/listings/{$listingId}/videos/{$videoId}");
    }

    // ── Encomendas: recibos, linhas, pagamentos, extrato ───────────────────

    /**
     * Os recibos (encomendas) da loja (getShopReceipts).
     *
     * @param  array<string, mixed>  $query  min_last_modified, min_created, was_paid, was_shipped, sort_on, limit, offset…
     * @return array<array-key, mixed>
     */
    public function receipts(int $shopId, array $query = []): array
    {
        return $this->client->get("/shops/{$shopId}/receipts", $query);
    }

    /**
     * Um recibo, com as linhas, os envios e os reembolsos (getShopReceipt).
     *
     * @return array<array-key, mixed>
     */
    public function receipt(int $shopId, int $receiptId): array
    {
        return $this->client->get("/shops/{$shopId}/receipts/{$receiptId}");
    }

    /**
     * Diz a Etsy que um recibo foi enviado (createReceiptShipment). Em JSON.
     * Sem `tracking_code` e `carrier_name` so o marca como enviado. Cada
     * chamada aceite e um email da Etsy ao comprador: quem chama NAO repete
     * isto sozinho.
     *
     * @param  array<string, mixed>  $shipment  tracking_code, carrier_name, note_to_buyer, send_bcc
     * @return array<array-key, mixed>
     */
    public function createReceiptShipment(int $shopId, int $receiptId, array $shipment): array
    {
        return $this->client->postJson("/shops/{$shopId}/receipts/{$receiptId}/tracking", $shipment);
    }

    /**
     * As marcas de um recibo (updateShopReceipt): was_paid, was_shipped.
     *
     * @param  array<string, bool>  $data
     * @return array<array-key, mixed>
     */
    public function updateReceipt(int $shopId, int $receiptId, array $data): array
    {
        return $this->client->putForm("/shops/{$shopId}/receipts/{$receiptId}", $data);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function receiptTransactions(int $shopId, int $receiptId): array
    {
        return $this->client->get("/shops/{$shopId}/receipts/{$receiptId}/transactions");
    }

    /**
     * @param  array<string, mixed>  $query  limit, offset
     * @return array<array-key, mixed>
     */
    public function listingTransactions(int $shopId, int $listingId, array $query = []): array
    {
        return $this->client->get("/shops/{$shopId}/listings/{$listingId}/transactions", $query);
    }

    /**
     * @param  array<string, mixed>  $query  limit, offset
     * @return array<array-key, mixed>
     */
    public function shopTransactions(int $shopId, array $query = []): array
    {
        return $this->client->get("/shops/{$shopId}/transactions", $query);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function transaction(int $shopId, int $transactionId): array
    {
        return $this->client->get("/shops/{$shopId}/transactions/{$transactionId}");
    }

    /**
     * O pagamento de um recibo: bruto, taxa de processamento e liquido
     * (getShopPaymentByReceiptId).
     *
     * @return array<array-key, mixed>
     */
    public function receiptPayments(int $shopId, int $receiptId): array
    {
        return $this->client->get("/shops/{$shopId}/receipts/{$receiptId}/payments");
    }

    /**
     * Pagamentos pelos seus ids (getPayments). A Etsy nao os da por datas:
     * para isso vai-se ao extrato.
     *
     * @param  array<int, int>  $paymentIds
     * @return array<array-key, mixed>
     */
    public function payments(int $shopId, array $paymentIds): array
    {
        return $this->client->get("/shops/{$shopId}/payments", ['payment_ids' => $paymentIds]);
    }

    /**
     * O extrato da conta de pagamentos, entre duas datas
     * (getShopPaymentAccountLedgerEntries). E aqui que estao TODAS as taxas:
     * transacao, publicacao, processamento.
     *
     * @param  array<string, mixed>  $query  min_created, max_created, limit, offset
     * @return array<array-key, mixed>
     */
    public function ledgerEntries(int $shopId, array $query): array
    {
        return $this->client->get("/shops/{$shopId}/payment-account/ledger-entries", $query);
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
