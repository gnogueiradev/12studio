<?php

namespace App\Support;

use App\Models\Variant;
use Illuminate\Support\Collection;

/**
 * O preco de um produto como o cliente o le: "12,90 €" ou "desde 12,90 €",
 * e o preco riscado quando a mais barata esta em promocao. So das variantes
 * que se vendem — quem chama passa-as ja filtradas.
 */
final class PriceRange
{
    private function __construct(
        public readonly ?int $fromCents,
        public readonly ?int $toCents,
        public readonly ?int $compareAtCents,
    ) {}

    /**
     * @param  Collection<int, Variant>  $variants
     */
    public static function of(Collection $variants): self
    {
        if ($variants->isEmpty()) {
            return new self(null, null, null);
        }

        $cheapest = $variants->sortBy('price_cents')->first();

        return new self(
            (int) $variants->min('price_cents'),
            (int) $variants->max('price_cents'),
            $cheapest->isOnSale() ? $cheapest->compare_at_cents : null,
        );
    }

    public function varies(): bool
    {
        return $this->fromCents !== $this->toCents;
    }

    /**
     * @return array{from: int|null, to: int|null, compareAt: int|null, varies: bool}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->fromCents,
            'to' => $this->toCents,
            'compareAt' => $this->compareAtCents,
            'varies' => $this->varies(),
        ];
    }
}
