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
        public ?int $maxQty, // o maximo que o cliente pode levar: min(stock, limite geral); null = so o limite geral (encomenda)
        public ?string $reason,
    ) {}

    /**
     * Quais destas variantes estao a venda, numa so consulta. As mesmas
     * condicoes da montra, pelos mesmos scopes: o que la nao se ve tambem nao
     * se compra. Quem tem uma lista (pagina do produto, carrinho) pergunta
     * aqui uma vez e passa a resposta a check().
     *
     * @param  iterable<Variant>  $variants
     * @return array<int, bool> por id da variante
     */
    public static function forSale(iterable $variants): array
    {
        $ids = collect($variants)->map(fn (Variant $variant): int => $variant->id)->unique()->values();

        // Os produtos saem de uma consulta de Product (e nao do whereHas da
        // relacao, que e withTrashed): assim o apagado fica de fora.
        $found = Variant::query()
            ->visibleInStore()
            ->whereIn('id', $ids)
            ->whereIn('product_id', Product::query()->visibleInStore()->select('products.id'))
            ->pluck('id')
            ->flip();

        return $ids->mapWithKeys(fn (int $id): array => [$id => $found->has($id)])->all();
    }

    /**
     * @param  bool|null  $forSale  a resposta de forSale() para esta variante;
     *                              sem ela, check() pergunta por si (uma consulta)
     */
    public static function check(Variant $variant, int $qty, ?bool $forSale = null): self
    {
        $product = $variant->product;
        $forSale ??= self::forSale([$variant])[$variant->id];

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
        $ceiling = min($available, (int) config('shop.cart.max_qty'));

        if ($qty <= $available) {
            // Com encomenda, pedir mais do que o stock tambem se aceita (a
            // linha passa a ser feita por encomenda): o teto e so o geral.
            return new self(true, 'in_stock', $product->allow_backorder ? null : $ceiling, null);
        }

        if ($product->allow_backorder) {
            return new self(true, 'made_to_order', null, null);
        }

        return self::no($available === 0 ? self::SOLD_OUT : self::OVER_STOCK, $ceiling);
    }

    private static function no(string $reason, ?int $maxQty = null): self
    {
        return new self(false, null, $maxQty, $reason);
    }
}
