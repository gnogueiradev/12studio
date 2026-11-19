<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\AddToCartRequest;
use App\Http\Requests\Shop\ApplyPromoCodeRequest;
use App\Http\Requests\Shop\UpdateCartItemRequest;
use App\Models\Cart;
use App\Models\Variant;
use App\Shop\CartCookie;
use App\Shop\CartPresenter;
use App\Shop\CartService;
use App\Store\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
        // O carrinho e de cada visitante: nao e para os motores de busca.
        return Inertia::render('store/cart', [
            'cart' => CartPresenter::make($this->current($request)),
        ])->withViewData(['seo' => Seo::make(
            path: '/carrinho',
            title: trans('store.cart.title'),
            index: false,
        )]);
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

        return CartCookie::saved($request, $cart);
    }

    public function update(UpdateCartItemRequest $request, int $item): RedirectResponse
    {
        $cart = $this->mine($request);

        $this->carts->setQty($cart, $cart->items()->findOrFail($item), $request->integer('qty'));

        return CartCookie::saved($request, $cart);
    }

    public function remove(Request $request, int $item): RedirectResponse
    {
        $cart = $this->mine($request);

        $this->carts->remove($cart, $cart->items()->findOrFail($item));

        return CartCookie::saved($request, $cart);
    }

    public function promo(ApplyPromoCodeRequest $request): RedirectResponse
    {
        $cart = $this->mine($request);

        $this->carts->setPromoCode($cart, (string) $request->validated('promo_code'));

        return CartCookie::saved($request, $cart);
    }

    public function removePromo(Request $request): RedirectResponse
    {
        $cart = $this->mine($request);

        $this->carts->setPromoCode($cart, null);

        return CartCookie::saved($request, $cart);
    }

    /** O carrinho do cookie, se houver; nunca cria. */
    private function current(Request $request): ?Cart
    {
        return $this->carts->fromRequest($request);
    }

    /** O carrinho do cookie para as escritas que só fazem sentido com um: sem ele, 404. */
    private function mine(Request $request): Cart
    {
        return $this->current($request) ?? abort(404);
    }
}
