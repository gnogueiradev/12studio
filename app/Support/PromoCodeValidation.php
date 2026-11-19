<?php

namespace App\Support;

use App\Models\PromoCode;

/**
 * O resultado de validar um codigo: valido com o desconto, ou o motivo da
 * recusa.
 */
final readonly class PromoCodeValidation
{
    public const NOT_FOUND = 'not_found';

    public const INACTIVE = 'inactive';

    public const NOT_STARTED = 'not_started';

    public const EXPIRED = 'expired';

    public const EXHAUSTED = 'exhausted';

    public const BELOW_MINIMUM = 'below_minimum';

    public const EMAIL_REQUIRED = 'email_required';

    public const WRONG_EMAIL = 'wrong_email';

    public const ALREADY_USED = 'already_used';

    private function __construct(
        public bool $valid,
        public ?PromoCode $promoCode,
        public int $discountCents,
        public ?string $reason,
        public ?string $message,
    ) {}

    public static function accepted(PromoCode $promoCode, int $discountCents): self
    {
        return new self(true, $promoCode, $discountCents, null, null);
    }

    public static function refused(string $reason, string $message, ?PromoCode $promoCode = null): self
    {
        return new self(false, $promoCode, 0, $reason, $message);
    }
}
