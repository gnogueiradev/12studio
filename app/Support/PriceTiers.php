<?php

namespace App\Support;

/**
 * Os tres precos sugeridos e a revenda que sai do Recomendado.
 *
 * Garantia de construcao: minimum < recommended < premium (com IVA).
 */
final readonly class PriceTiers
{
    /** A revenda saiu da fracao do Recomendado. */
    public const WHOLESALE_RULE_SHARE = 'share';

    /** A revenda saiu do chao: custo x (1 + IVA) x markup minimo. */
    public const WHOLESALE_RULE_FLOOR = 'floor';

    /** A revenda saiu do preco minimo de revenda das definicoes. */
    public const WHOLESALE_RULE_MINIMUM = 'minimum_price';

    public function __construct(
        public PriceTier $minimum,
        public PriceTier $recommended,
        public PriceTier $premium,
        /** Com IVA, terminado em ,49 / ,99. */
        public int $wholesalePriceMicros,
        public string $wholesaleRule,
    ) {}

    public function get(string $name): PriceTier
    {
        return match ($name) {
            PriceTier::MINIMUM => $this->minimum,
            PriceTier::RECOMMENDED => $this->recommended,
            default => $this->premium,
        };
    }

    /** @return array<string, PriceTier> */
    public function all(): array
    {
        return [
            PriceTier::MINIMUM => $this->minimum,
            PriceTier::RECOMMENDED => $this->recommended,
            PriceTier::PREMIUM => $this->premium,
        ];
    }
}
