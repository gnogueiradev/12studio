<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\AddToCartRequest;
use App\Http\Requests\Shop\ApplyPromoCodeRequest;
use App\Http\Requests\Shop\UpdateCartItemRequest;
use App\Models\Cart;
use App\Models\Variant;
use App\Shop\CartPresenter;
use App\Shop\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * O carrinho de quem compra sem conta. O token vive num cookie; só o
 * "adicionar" cria o carrinho (e o cookie), e uma leitura nunca cria nada.
 * As escritas voltam à página de onde vieram, com o carrinho já feito conta
 * no flash `cart`.
 */
class CartController extends Controller
{
    public function __construct(
        private CartService $carts,
    ) {}

    public function show(Request $request): Response
    {
        return Inertia::render('store/cart', [
            'cart' => CartPresenter::make($this->current($request)),
        ]);
    }

    public function add(AddToCartRequest $request): RedirectResponse
    {
        $variant = Variant::query()->findOrFail($request->integer('variant_id'));

        $cart = $this->carts->add(
            $this->current($request),
            $variant,
            $request->integer('qty'),
            (array) $request->validated('personalization', []),
        );

        return $this->saved($request, $cart);
    }

    public function update(UpdateCartItemRequest $request, int $item): RedirectResponse
    {
        $cart = $this->mine($request);

        $this->carts->setQty($cart, $cart->items()->findOrFail($item), $request->integer('qty'));

        return $this->saved($request, $cart);
    }

    public function remove(Request $request, int $item): RedirectResponse
    {
        $cart = $this->mine($request);

        $this->carts->remove($cart, $cart->items()->findOrFail($item));

        return $this->saved($request, $cart);
    }

    public function promo(ApplyPromoCodeRequest $request): RedirectResponse
    {
        $cart = $this->mine($request);

        $this->carts->setPromoCode($cart, (string) $request->validated('promo_code'));

        return $this->saved($request, $cart);
    }

    public function removePromo(Request $request): RedirectResponse
    {
        $cart = $this->mine($request);

        $this->carts->setPromoCode($cart, null);

        return $this->saved($request, $cart);
    }

    /** O carrinho do cookie, se houver; nunca cria. */
    private function current(Request $request): ?Cart
    {
        return $this->carts->find($request->cookies->getString((string) config('shop.cart.cookie')));
    }

    /** O carrinho do cookie para as escritas que só fazem sentido com um: sem ele, 404. */
    private function mine(Request $request): Cart
    {
        return $this->current($request) ?? abort(404);
    }

    /**
     * Volta à página com o carrinho no flash e o cookie renovado: o prazo
     * conta desde a última escrita, como o prune dos carrinhos.
     */
    private function saved(Request $request, Cart $cart): RedirectResponse
    {
        Inertia::flash('cart', CartPresenter::make($cart));

        return back()->withCookie($this->cookie($request, $cart));
    }

    private function cookie(Request $request, Cart $cart): Cookie
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
}
