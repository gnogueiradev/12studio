<?php

namespace App\Shop;

use App\Models\Product;
use App\Models\Variant;

/**
 * A unica regra do que se pode comprar, e como: da prateleira ou feito por
 * encomenda. A pagina do produto, o carrinho e a compra perguntam todos aqui,
 * para nenhum deles decidir por si.
 *
 * Uma linha e de stock ou e de producao, nunca metade de cada: se o stock nao
 * chega e o produto aceita espera, a linha inteira e feita por encomenda.
 */
final readonly class Buyable
{
    public const NOT_FOR_SALE = 'not_for_sale'; // fora da montra, variante escondida

    public const CUSTOM = 'custom';

    public const SOLD_OUT = 'sold_out';

    public const OVER_STOCK = 'over_stock'; // ha stock, mas menos do que o pedido

    public const BAD_QTY = 'bad_qty'; // fora de 1..limite geral

    private function __construct(
        public bool $ok,
        public ?string $mode, // 'in_stock' | 'made_to_order' quando ok
        public ?int $maxQty, // teto por stock; null = so o limite geral
        public ?string $reason,
    ) {}

    public static function check(Variant $variant, int $qty): self
    {
        $product = $variant->product;

        // As mesmas condicoes da montra, pelos mesmos scopes: o que la nao se
        // ve tambem nao se compra (o scope do produto tira rascunhos,
        // arquivados e apagados).
        $forSale = Product::query()->visibleInStore()->whereKey($product->getKey())->exists()
            && Variant::query()->scopes('visibleInStore')->whereKey($variant->getKey())->exists();

        if (! $forSale) {
            return self::no(self::NOT_FOR_SALE);
        }

        // Por medida nao tem preco: continua pela conversa.
        if ($product->isCustom()) {
            return self::no(self::CUSTOM);
        }

        if ($qty < 1 || $qty > (int) config('shop.cart.max_qty')) {
            return self::no(self::BAD_QTY);
        }

        if ($product->isMadeToOrder()) {
            return new self(true, 'made_to_order', null, null);
        }

        $available = max(0, $variant->available_stock);

        if ($qty <= $available) {
            return new self(true, 'in_stock', $available, null);
        }

        if ($product->allow_backorder) {
            return new self(true, 'made_to_order', null, null);
        }

        return self::no($available === 0 ? self::SOLD_OUT : self::OVER_STOCK, $available);
    }

    private static function no(string $reason, ?int $maxQty = null): self
    {
        return new self(false, null, $maxQty, $reason);
    }
}
