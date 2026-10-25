<?php

namespace App\Store;

use App\Models\Product;

/**
 * O produto com que o editor mostra o template da pagina de produto: um que
 * esteja a venda (os destaques primeiro), ou qualquer um se ainda nenhum
 * estiver.
 */
final class PreviewProduct
{
    public static function pick(): ?Product
    {
        return Product::query()->visibleInStore()->orderByDesc('featured')->latest('id')->first()
            ?? Product::query()->latest('id')->first();
    }
}
