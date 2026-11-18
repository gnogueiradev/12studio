<?php

namespace App\Http\Middleware;

use App\Shop\ShopGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O porteiro das compras (alias `shop.open`): com as compras desligadas para
 * este visitante, o carrinho e a compra nao existem — 404, como se a rota
 * nunca tivesse sido escrita.
 */
class EnsureShopOpen
{
    public function __construct(
        private ShopGate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->gate->allows($request->user()), 404);

        return $next($request);
    }
}
