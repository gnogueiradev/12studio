<?php

namespace App\Support;

use App\Models\Product;

/**
 * Referencia sugerida para uma variante, a partir do slug do produto
 * ("caixa-ambar" -> "CAIXA-AMBAR-3").
 *
 * Vive em Support porque a criacao de variantes tem duas portas: uma de cada
 * vez, no formulario da variante, e em bloco, na matriz do modal de novo
 * produto. As duas tem de numerar a partir do mesmo sitio, senao a segunda
 * gerava SKUs que ja existiam.
 */
class VariantSku
{
    /** O slug e unico por produto, por isso a base nunca colide entre produtos. */
    private const BASE_LENGTH = 20;

    /**
     * Sugestao para UMA variante. O admin pode reescrever; a unicidade e
     * garantida na validacao (StoreVariantRequest).
     */
    public static function next(Product $product): string
    {
        return self::at($product, $product->variants()->count() + 1);
    }

    /**
     * `$howMany` referencias seguidas, para a matriz do modal.
     *
     * A contagem e lida UMA vez e a numeracao segue dai — chamar o `next()` em
     * ciclo nao servia: as variantes vao sendo inseridas na mesma transacao, a
     * contagem seguinte ja as via, e a numeracao saltava.
     *
     * @return array<int, string>
     */
    public static function series(Product $product, int $howMany): array
    {
        $from = $product->variants()->count() + 1;

        return array_map(
            fn (int $number): string => self::at($product, $number),
            range($from, $from + $howMany - 1),
        );
    }

    private static function at(Product $product, int $number): string
    {
        $base = strtoupper(str($product->slug)->limit(self::BASE_LENGTH, '')->value());

        return $base.'-'.$number;
    }
}
