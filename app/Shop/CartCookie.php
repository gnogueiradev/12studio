<?php

namespace App\Shop;

use App\Models\Cart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * O cookie que leva o token do carrinho, partilhado por quem escreve num
 * carrinho (o CartController e o "comprar outra vez" da conta): um so sitio
 * para o nome, o prazo e as flags.
 */
final class CartCookie
{
    /**
     * Renovado a cada escrita: o prazo conta desde a última, como o prune dos
     * carrinhos.
     */
    public static function make(Request $request, Cart $cart): Cookie
    {
        return new Cookie(
            (string) config('shop.cart.cookie'),
            $cart->token,
            now()->addDays((int) config('shop.cart.days')),
            '/',
            null,
            (bool) (config('session.secure') ?? $request->isSecure()),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    /** Volta à página com o carrinho no flash e o cookie renovado. */
    public static function saved(Request $request, Cart $cart): RedirectResponse
    {
        Inertia::flash('cart', CartPresenter::make($cart));

        return back()->withCookie(self::make($request, $cart));
    }
}
