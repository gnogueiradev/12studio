<?php

namespace App\Support;

/**
 * As contas de uma encomenda, tudo em centimos com IVA incluido.
 */
final readonly class OrderTotals
{
    public function __construct(
        public int $subtotalCents,
        public int $discountCents,
        public int $shippingCents,
        public int $adjustmentCents,
        public int $totalCents,
    ) {}
}
