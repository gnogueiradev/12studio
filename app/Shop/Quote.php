<?php

namespace App\Shop;

/**
 * O carrinho feito conta: linhas e totais. So o CartQuoter o constroi.
 */
final readonly class Quote
{
    /** O minimo que o Stripe cobra, em centimos. */
    public const MINIMUM_TOTAL_CENTS = 50;

    /**
     * @param  list<QuoteLine>  $lines
     * @param  string|null  $promoStatus  'applied' | 'pending_email' | 'refused'
     */
    public function __construct(
        public array $lines,
        public int $subtotalCents,
        public int $discountCents,
        public int $shippingCents,
        public int $totalCents,
        public int $freeShippingLeftCents,
        public ?string $promoCode,
        public ?string $promoStatus,
        public ?string $promoMessage,
        public ?int $promoCodeId,
        public bool $problems,
        public bool $belowMinimum,
    ) {}

    public function canCheckout(): bool
    {
        return $this->lines !== [] && ! $this->problems && ! $this->belowMinimum;
    }
}
