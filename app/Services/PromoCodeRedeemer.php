<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Regista o uso de um codigo quando a encomenda fica paga.
 *
 * Em SQLite o lockForUpdate() nao faz nada, por isso quem guarda o `max_uses` e
 * um update condicional, a mesma tecnica do StockService. Dois pagamentos no
 * ultimo uso: so um conta.
 *
 * Nunca lanca por o codigo se ter esgotado entretanto: o cliente ja pagou, e
 * uma excecao aqui fazia o webhook falhar e o Stripe repeti-lo durante dias.
 */
class PromoCodeRedeemer
{
    /**
     * @return string|null O motivo por que nao contou; null se contou ou se nao ha nada a contar.
     */
    public function redeem(Order $order): ?string
    {
        if ($order->promo_code_id === null) {
            return null;
        }

        $problem = DB::transaction(fn (): ?string => $this->record($order));

        if ($problem !== null) {
            Log::channel('api')->warning('Uso de codigo de desconto nao registado.', [
                'order_id' => $order->getKey(),
                'promo_code_id' => $order->promo_code_id,
                'code' => $order->promo_code,
                'reason' => $problem,
            ]);
        }

        return $problem;
    }

    private function record(Order $order): ?string
    {
        // Reembolsada e paga outra vez: o uso ja la esta.
        if (PromoCodeRedemption::query()->where('order_id', $order->getKey())->exists()) {
            return null;
        }

        $promoCode = PromoCode::query()->find($order->promo_code_id);

        if ($promoCode === null) {
            return 'O código já não existe.';
        }

        $email = PromoCode::normalizeEmail($order->email);

        $inserted = PromoCodeRedemption::query()->insertOrIgnore([
            'promo_code_id' => $promoCode->getKey(),
            'order_id' => $order->getKey(),
            'email' => $email,
            'unique_email' => $promoCode->single_use_per_email ? $email : null,
            'discount_cents' => $order->discount_cents,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return 'Este email já tinha usado o código.';
        }

        $counted = PromoCode::query()
            ->whereKey($promoCode->getKey())
            ->where(fn ($query) => $query->whereNull('max_uses')->orWhereColumn('uses_count', '<', 'max_uses'))
            ->increment('uses_count');

        if ($counted === 0) {
            PromoCodeRedemption::query()->where('order_id', $order->getKey())->delete();

            return 'O código esgotou-se antes deste pagamento.';
        }

        return null;
    }
}
