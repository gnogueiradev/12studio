<?php

namespace App\Shop;

use App\Models\Cart;
use App\Models\Checkout;
use App\Stripe\StripeCheckout;
use App\Stripe\StripeClient;
use App\Stripe\StripeException;
use App\Support\LocalizedUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * O "Pagar" da loja: volta a fazer as contas do carrinho, congela a compra
 * num `checkout` (o retrato) e abre a sessao do Stripe a partir dele. Nao
 * cria encomenda — essa nasce no webhook, do retrato.
 *
 * Cobra-se o total que o cliente viu e nenhum outro: se as contas de agora
 * nao dao esse total (um preco mudou, o stock acabou, o codigo ja nao vale),
 * nada se cria e o cliente volta a pagina para ver o novo.
 */
class CheckoutService
{
    public function __construct(
        private CartQuoter $quoter,
        private StripeCheckout $stripeCheckout,
        private StripeClient $stripe,
    ) {}

    /**
     * O endereco para onde mandar o cliente: a pagina do Stripe, ou a
     * pagina da compra anterior deste carrinho se essa ja foi paga.
     *
     * @param  array{name: string, email: string, phone: string, nif: string|null}  $customer
     * @param  array{line1: string, line2: string|null, postal_code: string, city: string}  $address
     *
     * @throws ValidationException quando o carrinho nao deixa pagar, o codigo nao vale ou o total mudou
     * @throws StripeException
     */
    public function start(Cart $cart, array $customer, array $address, string $locale, int $expectedTotalCents): string
    {
        // Dois cliques (ou duas abas) ao mesmo tempo liam a mesma sessao
        // anterior e abriam duas novas, ambas pagaveis. Um de cada vez.
        $lock = Cache::lock("shop-checkout:{$cart->id}", StripeCheckout::LOCK_SECONDS);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['cart' => trans('store.checkout.errors.busy')]);
        }

        try {
            return $this->startLocked($cart, $customer, $address, $locale, $expectedTotalCents);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{name: string, email: string, phone: string, nif: string|null}  $customer
     * @param  array{line1: string, line2: string|null, postal_code: string, city: string}  $address
     */
    private function startLocked(Cart $cart, array $customer, array $address, string $locale, int $expectedTotalCents): string
    {
        // Ja pago vem primeiro: quem pagou (ou gerou a referencia) e voltou
        // atras vai ver essa compra, mesmo que um preco tenha mudado entretanto.
        [$previous, $paid] = $this->previous($cart);

        if ($paid !== null) {
            return url(LocalizedUrl::path("/compra/{$paid->token}", $locale));
        }

        $quote = $this->quoter->quote($cart, $customer['email']);

        if (! $quote->canCheckout()) {
            throw ValidationException::withMessages(['cart' => trans('store.checkout.errors.cart')]);
        }

        // Um codigo que nao fica aplicado com este email nao se cobra sem
        // ele as escondidas: o cliente corrige o email ou retira o codigo.
        if ($quote->promoStatus !== null && $quote->promoStatus !== 'applied') {
            throw ValidationException::withMessages(['promo_code' => (string) $quote->promoMessage]);
        }

        if ($quote->totalCents !== $expectedTotalCents) {
            throw ValidationException::withMessages(['cart' => trans('store.checkout.errors.total_changed')]);
        }

        $paid = $this->expire($previous);

        if ($paid !== null) {
            return url(LocalizedUrl::path("/compra/{$paid->token}", $locale));
        }

        $checkout = Checkout::query()->create([
            'token' => Str::random(64),
            'cart_id' => $cart->id,
            'items_snapshot' => array_map(fn (QuoteLine $line): array => [
                'variant_id' => $line->variant->id,
                'product_name' => $line->productName,
                'variant_label' => $line->variantLabel,
                'sku' => $line->sku,
                'qty' => $line->qty,
                'unit_price_cents' => $line->unitCents,
                'personalization_surcharge_cents' => $line->surchargeCents,
                'line_total_cents' => $line->lineCents,
                'vat_rate' => $line->vatRate,
                'personalization' => $line->personalization,
                'fulfillment_mode' => $line->mode,
                'image_url' => $line->imageUrl,
            ], $quote->lines),
            'customer' => $customer,
            'shipping_address' => [
                'name' => $customer['name'],
                'line1' => $address['line1'],
                'line2' => $address['line2'],
                'postalCode' => $address['postal_code'],
                'city' => $address['city'],
                'country' => 'PT',
                'phone' => $customer['phone'],
            ],
            'subtotal_cents' => $quote->subtotalCents,
            'shipping_cents' => $quote->shippingCents,
            'discount_cents' => $quote->discountCents,
            'promo_code_id' => $quote->promoCodeId,
            'promo_code' => $quote->promoStatus === 'applied' ? $quote->promoCode : null,
            'total_cents' => $quote->totalCents,
            'locale' => $locale,
            'status' => 'open',
        ]);

        try {
            return $this->stripeCheckout->startForCheckout(
                $checkout,
                url(LocalizedUrl::path("/compra/{$checkout->token}", $locale)),
                url(LocalizedUrl::path('/finalizar', $locale)),
            );
        } catch (StripeException $exception) {
            $checkout->update(['status' => 'expired']);

            // O que o Stripe diz sobe para o report(), mas sem os dados do
            // cliente: o Stripe pode repetir o email que recebeu.
            throw $exception->withoutPersonalData([
                $customer['email'], $customer['phone'], $customer['name'], $customer['nif'],
                $address['line1'], $address['line2'], $address['city'], $address['postal_code'],
            ]);
        }
    }

    /**
     * As compras ainda abertas deste carrinho, e a que o Stripe ja deu por
     * completa (pago, ou referencia Multibanco gerada), se houver uma.
     *
     * Uma sessao que o Stripe nao conhece (404) so se da por perdida com
     * provas de que ja nao se pode pagar: e de outro modo que nao o da chave
     * em uso (as chaves de teste trocadas pelas de producao), ou o prazo dela
     * ja passou. Ai essa compra passa a expirada e abre-se outra. Sem provas,
     * e qualquer outra falha a ler, sobe — sem saber se a anterior ainda se
     * pode pagar, nao se abre uma segunda.
     *
     * @return array{0: array<int, array{checkout: Checkout, status: string|null}>, 1: Checkout|null}
     */
    private function previous(Cart $cart): array
    {
        $open = [];

        foreach (Checkout::query()->where('cart_id', $cart->id)->where('status', 'open')->get() as $checkout) {
            $status = null;

            if ($checkout->stripe_session_id !== null) {
                try {
                    $status = $this->stripe->get("checkout/sessions/{$checkout->stripe_session_id}")['status'] ?? null;
                } catch (StripeException $exception) {
                    $gone = $exception->status === 404 && (
                        $this->stripe->isOtherMode($checkout->stripe_session_id)
                        || $checkout->expires_at?->isPast() === true
                    );

                    // Sobe para o controlador, que a reporta e mostra o erro
                    // generico ao cliente.
                    if (! $gone) {
                        throw $exception;
                    }

                    report($exception);
                    $checkout->update(['status' => 'expired']);

                    continue;
                }
            }

            if ($status === 'complete') {
                return [[], $checkout];
            }

            $open[] = ['checkout' => $checkout, 'status' => is_string($status) ? $status : null];
        }

        return [$open, null];
    }

    /**
     * Uma so sessao viva por carrinho: as compras anteriores ainda abertas
     * passam a expiradas, e as sessoes delas tambem. Se uma foi paga entre a
     * leitura e o expire, e devolvida em vez de se abrir outra.
     *
     * @param  array<int, array{checkout: Checkout, status: string|null}>  $previous
     */
    private function expire(array $previous): ?Checkout
    {
        foreach ($previous as ['checkout' => $checkout, 'status' => $status]) {
            if ($status === 'open') {
                try {
                    $this->stripe->post("checkout/sessions/{$checkout->stripe_session_id}/expire");
                } catch (StripeException $exception) {
                    // Ja nao estava aberta: expirou sozinha entretanto (nao
                    // impede a nova) ou o cliente acabou de a pagar.
                    report($exception);

                    if (($this->stripe->get("checkout/sessions/{$checkout->stripe_session_id}")['status'] ?? null) === 'complete') {
                        return $checkout;
                    }
                }
            }

            $checkout->update(['status' => 'expired']);
        }

        return null;
    }
}
