<?php

namespace App\Shop;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Variant;
use Illuminate\Validation\ValidationException;

/**
 * "Comprar outra vez": volta a pôr no carrinho o que uma encomenda levou — as
 * mesmas variantes, quantidades e personalizações. O preço é o de hoje (quem
 * cota é o CartQuoter) e a regra do que se vende é a de sempre (Buyable, pelo
 * CartService): o que já não se compra fica de fora e devolve-se o nome, para
 * a conta avisar em vez de falhar calada.
 */
class Reorder
{
    public function __construct(
        private CartService $carts,
    ) {}

    /**
     * @return array{cart: ?Cart, skipped: list<string>} o carrinho (o que veio,
     *                                                   ou o criado; null se nada entrou) e os nomes do que ficou de fora
     */
    public function into(?Cart $cart, Order $order): array
    {
        $skipped = [];

        foreach ($order->items()->with('variant.product')->get() as $item) {
            $variant = $item->variant;

            if (! $variant instanceof Variant) {
                $skipped[] = $item->product_name;

                continue;
            }

            try {
                $cart = $this->carts->add($cart, $variant, $item->qty, $item->personalization ?? []);
            } catch (ValidationException) {
                $skipped[] = $item->product_name;
            }
        }

        return ['cart' => $cart, 'skipped' => $skipped];
    }
}
