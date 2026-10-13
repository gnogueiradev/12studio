<?php

namespace App\Etsy;

use App\Models\EtsyListing;

/**
 * Que anuncios usam um perfil de envio, um perfil de processamento, uma
 * politica de devolucao ou uma seccao.
 *
 * Conta-se pelo que o 12studio sabe (a tabela etsy_listings), nao perguntando
 * a Etsy: e o que decide se uma alteracao pede confirmacao e se um perfil se
 * pode apagar, e isso tem de se saber sem gastar um pedido por perfil.
 */
class EtsyUsage
{
    public const SHIPPING_PROFILE = 'shipping_profile_id';

    public const PROCESSING_PROFILE = 'readiness_state_id';

    public const RETURN_POLICY = 'return_policy_id';

    public const SECTION = 'shop_section_id';

    /**
     * Por id: quantos anuncios o usam, e quantos desses estao a venda.
     *
     * @param  self::*  $column
     * @return array<int, array{listings: int, active: int}>
     */
    public function counts(string $column): array
    {
        $counts = [];

        foreach (EtsyListing::query()->whereNotNull($column)->get([$column, 'state']) as $listing) {
            $id = (int) $listing->getAttribute($column);

            $counts[$id] ??= ['listings' => 0, 'active' => 0];
            $counts[$id]['listings']++;

            if ($listing->state === 'active') {
                $counts[$id]['active']++;
            }
        }

        return $counts;
    }

    /**
     * @param  self::*  $column
     */
    public function active(string $column, int $id): int
    {
        return EtsyListing::query()->where($column, $id)->where('state', 'active')->count();
    }

    /**
     * Os nomes dos produtos que o usam, para dizer a quem quer apagar o que
     * tem de mudar primeiro.
     *
     * @param  self::*  $column
     * @return array<int, string>
     */
    public function products(string $column, int $id, int $max = 5): array
    {
        return EtsyListing::query()
            ->where($column, $id)
            ->with('product:id,name,deleted_at')
            ->limit($max)
            ->get()
            ->map(fn (EtsyListing $listing): string => $listing->product->name)
            ->all();
    }

    /**
     * Anuncios a venda, sejam de que perfil forem.
     */
    public function activeListings(): int
    {
        return EtsyListing::query()->where('state', 'active')->count();
    }
}
