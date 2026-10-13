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
}
