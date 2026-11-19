<?php

namespace App\Services;

use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Support\PromoCodeValidation as Validation;
use Illuminate\Support\Facades\Log;

/**
 * Diz se um codigo serve para esta compra e quanto desconta. So le: o uso so se
 * regista quando a encomenda fica paga (PromoCodeRedeemer).
 *
 * Nao acumula por construcao: uma encomenda so tem lugar para um codigo.
 */
class PromoCodeValidator
{
    /**
     * @param  array<string, mixed>  $context  order_id ou cart_id, para o log.
     */
    public function validate(string $code, int $subtotalCents, ?string $email, array $context = []): Validation
    {
        $code = PromoCode::normalizeCode($code);
        $result = $this->check($code, $subtotalCents, PromoCode::normalizeEmail($email));

        Log::channel('api')->info('Validacao de codigo de desconto.', [
            'code' => $code,
            ...$context,
            'result' => $result->valid ? 'valid' : 'refused',
            'reason' => $result->reason,
        ]);

        return $result;
    }

    private function check(string $code, int $subtotalCents, ?string $email): Validation
    {
        $promoCode = PromoCode::query()->where('code', $code)->first();

        if ($promoCode === null) {
            return Validation::refused(Validation::NOT_FOUND, 'Este código não existe.');
        }

        $refusal = $this->refusal($promoCode, $subtotalCents, $email);

        return $refusal ?? Validation::accepted($promoCode, $promoCode->discountFor($subtotalCents));
    }

    private function refusal(PromoCode $promoCode, int $subtotalCents, ?string $email): ?Validation
    {
        if (! $promoCode->is_active) {
            return Validation::refused(Validation::INACTIVE, 'Este código não está ativo.', $promoCode);
        }

        if ($promoCode->starts_at?->isFuture()) {
            return Validation::refused(Validation::NOT_STARTED, 'Este código ainda não está em vigor.', $promoCode);
        }

        if ($promoCode->expires_at?->isPast()) {
            return Validation::refused(Validation::EXPIRED, 'Este código já expirou.', $promoCode);
        }

        if ($promoCode->max_uses !== null && $promoCode->uses_count >= $promoCode->max_uses) {
            return Validation::refused(Validation::EXHAUSTED, 'Este código já foi usado o número máximo de vezes.', $promoCode);
        }

        if ($subtotalCents < $promoCode->min_subtotal_cents) {
            return Validation::refused(Validation::BELOW_MINIMUM, sprintf(
                'Este código só se aplica a partir de %s €.',
                number_format($promoCode->min_subtotal_cents / 100, 2, ',', ' '),
            ), $promoCode);
        }

        if (($promoCode->email !== null || $promoCode->single_use_per_email) && $email === null) {
            return Validation::refused(Validation::EMAIL_REQUIRED, 'Este código precisa do email do cliente.', $promoCode);
        }

        if ($promoCode->email !== null && $promoCode->email !== $email) {
            return Validation::refused(Validation::WRONG_EMAIL, 'Este código é pessoal e pertence a outro email.', $promoCode);
        }

        if ($promoCode->single_use_per_email && PromoCodeRedemption::query()
            ->where('promo_code_id', $promoCode->getKey())
            ->where('email', $email)
            ->exists()) {
            return Validation::refused(Validation::ALREADY_USED, 'Este email já usou este código.', $promoCode);
        }

        return null;
    }
}
