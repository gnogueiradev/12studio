<?php

namespace App\Etsy;

use App\Models\Variant;

/**
 * Que preco leva uma variante para a Etsy.
 *
 * O dono ainda nao decidiu a politica, e por isso sao tres degraus, do mais
 * especifico para o mais geral:
 *
 *   1. o preco proprio da variante na Etsy, se tiver um;
 *   2. o preco do site mais a margem global, se houver uma;
 *   3. o preco do site, tal e qual.
 *
 * Sempre com IVA incluido, como no site: e o que o comprador paga.
 */
class EtsyPricePolicy
{
    public const SOURCE_OWN = 'own';

    public const SOURCE_MARKUP = 'markup';

    public const SOURCE_SITE = 'site';

    public function __construct(
        private EtsySettings $settings,
    ) {}

    public function priceCents(Variant $variant): int
    {
        $own = $variant->etsyVariant?->etsy_price_cents;

        if ($own !== null) {
            return $own;
        }

        // Arredondado ao centimo: a Etsy nao tem meio centimo, e truncar
        // tirava sempre para o mesmo lado.
        return (int) round($variant->price_cents * (10_000 + $this->settings->markupBp()) / 10_000);
    }

    /**
     * De onde vem o preco — para o ecra dizer "preco proprio" ou "site + 10 %".
     *
     * @return self::SOURCE_*
     */
    public function source(Variant $variant): string
    {
        return match (true) {
            $variant->etsyVariant?->etsy_price_cents !== null => self::SOURCE_OWN,
            $this->settings->markupBp() > 0 => self::SOURCE_MARKUP,
            default => self::SOURCE_SITE,
        };
    }
}
