<?php

namespace App\Stripe;

/**
 * O link de pagamento gerado para uma encomenda, para copiar e mandar por DM.
 */
final readonly class PaymentLink
{
    public function __construct(
        public string $url,
        public string $sessionId,
        public int $amountTotalCents,
        public int $expiresAt,
        public bool $reused,
    ) {}
}
