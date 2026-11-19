<?php

namespace App\Services;

use App\Support\OrderTotals;

/**
 * As contas do total de uma encomenda, num so sitio: a loja e o backoffice
 * usam as mesmas.
 *
 *     total = subtotal - desconto + portes + ajuste
 *
 * O ajuste e o valor com sinal que o dono acerta depois de a encomenda existir.
 * Fica na conta porque e parte do total que o dashboard e a ficha de cliente
 * somam em SQL.
 */
class OrderTotalsCalculator
{
    /**
     * @param  int|null  $shippingCents  Os portes decididos a mao; null usa os sugeridos.
     */
    public function calculate(
        int $subtotalCents,
        int $discountCents = 0,
        ?int $shippingCents = null,
        int $adjustmentCents = 0,
    ): OrderTotals {
        $shipping = $shippingCents ?? $this->suggestedShipping($subtotalCents);

        return new OrderTotals(
            subtotalCents: $subtotalCents,
            discountCents: $discountCents,
            shippingCents: $shipping,
            adjustmentCents: $adjustmentCents,
            totalCents: $subtotalCents - $discountCents + $shipping + $adjustmentCents,
        );
    }

    /**
     * Os portes nacionais. O limite olha para o subtotal ANTES do desconto: um
     * codigo nao faz o cliente voltar a pagar portes.
     */
    public function suggestedShipping(int $subtotalCents): int
    {
        return $subtotalCents >= (int) config('shop.shipping.free_above_cents')
            ? 0
            : (int) config('shop.shipping.national_cents');
    }
}
