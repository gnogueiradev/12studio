<?php

namespace App\Mcp\Tools\Write\Concerns;

use App\Models\ProductImage;
use Laravel\Mcp\Response;

/**
 * Encontra uma fotografia cujo produto ainda existe.
 *
 * A relacao `product` respeita o SoftDeletes: num produto apagado vem null.
 * Mexer nas fotografias de um produto que o dono ja nao ve em lado nenhum e
 * o mesmo erro que o RefusesDeletedProducts trava nas variantes.
 */
trait FindsProductImages
{
    protected function findImage(int $id): ProductImage|Response
    {
        $image = ProductImage::query()->with('product')->find($id);

        if ($image === null) {
            return Response::error('Fotografia não encontrada.');
        }

        if ($image->product === null) {
            return Response::error('O produto desta fotografia foi apagado. Recupera-o primeiro com product_restore.');
        }

        return $image;
    }
}
