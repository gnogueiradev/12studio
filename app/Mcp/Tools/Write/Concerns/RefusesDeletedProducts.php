<?php

namespace App\Mcp\Tools\Write\Concerns;

use App\Models\Variant;
use Laravel\Mcp\Response;

/**
 * As variantes de um produto apagado continuam a existir por id — as
 * encomendas e os movimentos de stock apontam para elas. O que nao podem e
 * continuar a ser mexidas: seria alterar preco ou stock de um produto que o
 * dono ja nao ve em lado nenhum.
 */
trait RefusesDeletedProducts
{
    protected function refusalIfProductDeleted(Variant $variant): ?Response
    {
        if (! $variant->product->trashed()) {
            return null;
        }

        return Response::error('O produto desta variante foi apagado. Recupera-o primeiro com product_restore.');
    }
}
