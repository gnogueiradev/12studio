<?php

namespace App\Http\Middleware;

use App\Shop\ShopGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        // Sempre a guarda da equipa: um `auth:customer` na rota trocava a
        // guarda por omissao, e o `$request->user()` passava a ser o cliente.
        abort_unless($this->gate->allows(Auth::guard('web')->user()), 404);

        return $next($request);
    }
}
