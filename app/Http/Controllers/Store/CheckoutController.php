<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StartCheckoutRequest;
use App\Models\Cart;
use App\Models\Checkout;
use App\Shop\CartPresenter;
use App\Shop\CartService;
use App\Shop\CheckoutService;
use App\Store\Seo;
use App\Stripe\StripeException;
use App\Support\LocalizedUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * O /finalizar: os dados de quem compra, o resumo e o "Pagar". Abrir a pagina
 * nao cria nada (nem e indexada); so o "Pagar" congela a compra e abre a
 * sessao do Stripe (CheckoutService). Um carrinho que ainda nao se pode pagar
 * volta ao /carrinho, que explica porque.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private CartService $carts,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $current = $this->carts->fromRequest($request);
        $cart = CartPresenter::make($current);

        if (! $cart['canCheckout']) {
            return redirect(LocalizedUrl::path('/carrinho'));
        }

        return Inertia::render('store/checkout', [
            'cart' => $cart,
            'customer' => $current === null ? null : $this->lastCustomer($current),
        ])->withViewData(['seo' => Seo::make(
            path: '/finalizar',
            title: trans('store.checkout.title'),
            index: false,
        )]);
    }

    /**
     * Os dados do ultimo "Pagar" deste carrinho ainda em aberto: quem volta
     * atras na pagina do Stripe (o cancel_url e este) nao escreve tudo outra
     * vez. So durante a vida da sessao do Stripe (`expires_at`): e a unica
     * janela em que se volta de la, e passada ela o cookie do carrinho (60
     * dias) podia mostrar os dados de uma pessoa a quem abrisse o /finalizar
     * num browser partilhado. Uma compra concluida ja nao os tem. So le.
     *
     * @return array{name: string, email: string, phone: string, nif: string, line1: string, line2: string, postal_code: string, city: string}|null
     */
    private function lastCustomer(Cart $cart): ?array
    {
        $checkout = Checkout::query()
            ->where('cart_id', $cart->id)
            ->where('status', 'open')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        $customer = $checkout?->customer;
        $address = $checkout?->shipping_address;

        if ($customer === null || $address === null) {
            return null;
        }

        return [
            'name' => $customer['name'],
            'email' => $customer['email'],
            'phone' => $customer['phone'],
            'nif' => $customer['nif'] ?? '',
            'line1' => $address['line1'],
            'line2' => $address['line2'] ?? '',
            'postal_code' => $address['postalCode'],
            'city' => $address['city'],
        ];
    }

    /**
     * Um codigo pessoal so se confirma com o email, que o cliente escreve
     * aqui. Nao grava nada: devolve o carrinho feito conta com esse email,
     * para a pagina mostrar (e o "Pagar" mandar) o total com o desconto.
     */
    public function promo(Request $request): RedirectResponse
    {
        $cart = $this->carts->fromRequest($request) ?? abort(404);

        $email = $request->validate(
            ['email' => ['required', 'string', 'email:rfc', 'max:255']],
            [],
            ['email' => trans('store.attributes.email')],
        )['email'];

        Inertia::flash('cart', CartPresenter::make($cart, (string) $email));

        return back();
    }

    public function pay(StartCheckoutRequest $request, CheckoutService $checkouts): SymfonyResponse|RedirectResponse
    {
        $cart = $this->carts->fromRequest($request);

        if ($cart === null) {
            throw ValidationException::withMessages(['cart' => trans('store.checkout.errors.cart')]);
        }

        $customer = $request->customer();

        try {
            $url = $checkouts->start(
                $cart,
                $customer,
                $request->address(),
                app()->getLocale(),
                $request->integer('expected_total_cents'),
            );
        } catch (ValidationException $exception) {
            // As contas mudaram (um preco, o stock, ou um codigo pessoal que
            // este email confirmou): a pagina mostra as de agora, ja com este
            // email, e o proximo "Pagar" leva o total que la esta.
            Inertia::flash('cart', CartPresenter::make($cart, $customer['email']));

            throw $exception;
        } catch (StripeException $exception) {
            // O cliente nao precisa de saber o que o Stripe disse; o dono sim.
            report($exception);

            return redirect(LocalizedUrl::path('/finalizar'))
                ->withErrors(['payment' => trans('store.checkout.errors.payment')]);
        }

        // Para o Inertia, um 409 que o leva para fora; para um formulario
        // normal, um redirect.
        return Inertia::location($url);
    }
}
