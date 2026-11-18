<?php

namespace App\Stripe;

use App\Models\Variant;

/**
 * Uma linha do que se cobra. Com a variante (e o produto dela carregado), o
 * Stripe pode usar o Price ou o Product que ja conhece; sem ela, a linha vai
 * pelo nome.
 */
final readonly class ChargeLine
{
    public function __construct(
        public string $name,
        public int $qty,
        public int $lineTotalCents,
        public ?Variant $variant = null,
    ) {}
}
