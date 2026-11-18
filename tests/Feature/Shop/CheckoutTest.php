<?php

namespace Tests\Feature\Shop;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Variant;
use App\Services\SettingService;
use App\Services\StoreSettings;
use App\Shop\CheckoutCompletion;
use App\Shop\CheckoutService;
use App\Stripe\StripeClient;
use App\Stripe\StripeException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Stripe\FakesStripe;
use Tests\TestCase;

/**
 * O /finalizar e o "Pagar". Abrir a pagina nao cria nada; pagar congela a
 * compra num `checkout` (o retrato) e abre a sessao do Stripe a partir dele,
 * pelo total que o cliente viu e por nenhum outro. A encomenda so nasce no
 * webhook, noutro sitio.
 */
class CheckoutTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
        $this->fakeStripe();
        app(SettingService::class)->set(StoreSettings::KEY_SHOP, 'on');
        RateLimiter::clear('cart');
        RateLimiter::clear('checkout');
    }

    /** Um carrinho com um vaso grande; o cookie com o token vai em cada pedido. */
    private function cartWith(int $priceCents = 1500, int $qty = 2): Cart
    {
        $product = Product::factory()->create(['name' => 'Vaso']);
        $variant = Variant::factory()->for($product)->create(['price_cents' => $priceCents, 'size_label' => 'G']);
        $cart = Cart::factory()->create();
        CartItem::factory()->for($cart)->for($variant)->create(['qty' => $qty]);

        return $cart;
    }

    private function holding(Cart $cart): static
    {
        return $this->withCookie((string) config('shop.cart.cookie'), $cart->token);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function customer(int $expectedTotalCents, array $overrides = []): array
    {
        return [
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'phone' => '912 345 678',
            'nif' => '123456789',
            'line1' => 'Rua das Flores 12',
            'line2' => '2.º esq.',
            'postal_code' => '1000-001',
            'city' => 'Lisboa',
            'terms' => '1',
            'expected_total_cents' => $expectedTotalCents,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pay(Cart $cart, array $data): TestResponse
    {
        return $this->holding($cart)->from('/finalizar')->post('/finalizar/pagar', $data);
    }

    /** @return array<string, mixed> */
    private function flashed(): array
    {
        return (array) session('inertia.flash_data.cart');
    }

    public function test_opening_the_checkout_creates_nothing(): void
    {
        $cart = $this->cartWith();
        $this->stripeCalls = [];

        $this->holding($cart)->get('/finalizar')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex">', false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/checkout')
                ->where('cart.totalCents', 3000)
                ->where('cart.canCheckout', true));

        $this->assertSame(0, Checkout::query()->count());
        $this->assertSame([], $this->stripeCalls);
    }

    public function test_coming_back_from_stripe_keeps_the_details_typed(): void
    {
        $cart = $this->cartWith();

        $this->holding($cart)->get('/finalizar')->assertInertia(fn (Assert $page) => $page->where('customer', null));

        $this->pay($cart, $this->customer(3000))->assertSessionHasNoErrors();
        $this->stripeCalls = [];

        // O "voltar" da pagina do Stripe (cancel_url) traz o formulario cheio.
        $this->holding($cart)->get('/finalizar')->assertInertia(fn (Assert $page) => $page->where('customer', [
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'phone' => '912 345 678',
            'nif' => '123456789',
            'line1' => 'Rua das Flores 12',
            'line2' => '2.º esq.',
            'postal_code' => '1000-001',
            'city' => 'Lisboa',
        ]));

        $this->assertSame(1, Checkout::query()->count());
        $this->assertSame([], $this->stripeCalls);

        // Outro visitante, com o seu carrinho, nao ve os dados deste.
        $this->holding($this->cartWith())->get('/finalizar')->assertInertia(fn (Assert $page) => $page->where('customer', null));
    }

    public function test_a_completed_purchase_does_not_fill_the_form(): void
    {
        $cart = $this->cartWith();
        $this->pay($cart, $this->customer(3000))->assertSessionHasNoErrors();

        app(CheckoutCompletion::class)->orderFor(Checkout::query()->firstOrFail(), true);
        $this->assertSame(0, $cart->items()->count());

        // O mesmo visitante volta a encher o carrinho.
        CartItem::factory()->for($cart)->for(Variant::factory()->create(['price_cents' => 1500]))->create(['qty' => 1]);

        $this->holding($cart)->get('/finalizar')->assertOk()->assertInertia(fn (Assert $page) => $page->where('customer', null));
    }

    public function test_an_empty_or_blocked_cart_goes_back_to_the_cart(): void
    {
        $this->get('/finalizar')->assertRedirect('/carrinho');
        $this->holding(Cart::factory()->create())->get('/finalizar')->assertRedirect('/carrinho');

        $soldOut = $this->cartWith();
        $soldOut->items()->firstOrFail()->variant->update(['stock' => 0]);
        $this->holding($soldOut)->get('/finalizar')->assertRedirect('/carrinho');

        PromoCode::factory()->create(['code' => 'QUASE', 'value' => 2580]);
        $belowMinimum = $this->cartWith(2600, 1);
        $belowMinimum->update(['promo_code' => 'QUASE']);
        $this->holding($belowMinimum)->get('/finalizar')->assertRedirect('/carrinho');

        $this->get('/en/finalizar')->assertRedirect('/en/carrinho');

        $this->pay($soldOut, $this->customer(3000))->assertRedirect('/finalizar')->assertSessionHasErrors('cart');
        $this->assertSame(0, Checkout::query()->count());
    }

    public function test_paying_freezes_the_purchase_and_opens_a_stripe_session(): void
    {
        $cart = $this->cartWith();
        $variant = $cart->items()->firstOrFail()->variant;
        $this->stripeCalls = [];

        $response = $this->pay($cart, $this->customer(3000));

        $this->assertSame(1, Checkout::query()->count());
        $checkout = Checkout::query()->firstOrFail();

        $this->assertSame('open', $checkout->status);
        $this->assertSame(64, strlen((string) $checkout->token));
        $this->assertSame($cart->id, $checkout->cart_id);
        $this->assertSame('pt', $checkout->locale);
        $this->assertSame(3000, $checkout->subtotal_cents);
        $this->assertSame(0, $checkout->shipping_cents);
        $this->assertSame(0, $checkout->discount_cents);
        $this->assertSame(3000, $checkout->total_cents);
        $this->assertNull($checkout->order_id);
        $this->assertSame([[
            'variant_id' => $variant->id,
            'product_name' => 'Vaso',
            'variant_label' => 'G',
            'sku' => $variant->sku,
            'qty' => 2,
            'unit_price_cents' => 1500,
            'personalization_surcharge_cents' => 0,
            'line_total_cents' => 3000,
            'vat_rate' => $variant->product->vat_rate,
            'personalization' => null,
            'fulfillment_mode' => 'in_stock',
            'image_url' => null,
        ]], $checkout->items_snapshot);
        $this->assertSame(['name' => 'Ana Silva', 'email' => 'ana@example.com', 'phone' => '912 345 678', 'nif' => '123456789'], $checkout->customer);
        $this->assertSame([
            'name' => 'Ana Silva',
            'line1' => 'Rua das Flores 12',
            'line2' => '2.º esq.',
            'postalCode' => '1000-001',
            'city' => 'Lisboa',
            'country' => 'PT',
            'phone' => '912 345 678',
        ], $checkout->shipping_address);

        $this->assertCount(1, $this->stripe['sessions']);
        $session = array_values($this->stripe['sessions'])[0];
        $this->assertSame("checkout-{$checkout->id}", $session['client_reference_id']);
        $this->assertSame(['checkout_id' => (string) $checkout->id], $session['metadata']);
        $this->assertSame(3000, $session['amount_total']);
        $this->assertSame('ana@example.com', $session['request']['customer_email']);
        $this->assertSame(url("/compra/{$checkout->token}"), $session['request']['success_url']);
        $this->assertSame(url('/finalizar'), $session['request']['cancel_url']);
        $this->assertSame('Compra na loja: 2× Vaso (G).', $session['request']['custom_text']['submit']['message']);
        $this->assertEqualsWithDelta(now()->addMinutes(60)->getTimestamp(), $session['expires_at'], 5);
        $this->assertSame($session['id'], $checkout->stripe_session_id);
        $this->assertSame($session['expires_at'], $checkout->expires_at?->getTimestamp());
        $this->assertSame("checkout-{$checkout->id}-session", $this->stripeCallsTo('POST', 'checkout/sessions')[0]['idempotency']);

        // Ao preco normal, a linha vai pelo Price da variante.
        $this->assertNotNull($variant->fresh()?->stripe_price_id);
        $this->assertSame($variant->fresh()?->stripe_price_id, $session['request']['line_items'][0]['price']);

        $response->assertRedirect($session['url']);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_the_return_addresses_keep_the_language(): void
    {
        $cart = $this->cartWith();

        $this->holding($cart)->from('/en/finalizar')->post('/en/finalizar/pagar', $this->customer(3000))
            ->assertRedirect();

        $checkout = Checkout::query()->firstOrFail();
        $session = array_values($this->stripe['sessions'])[0];

        $this->assertSame('en', $checkout->locale);
        $this->assertSame(url("/en/compra/{$checkout->token}"), $session['request']['success_url']);
        $this->assertSame(url('/en/finalizar'), $session['request']['cancel_url']);
    }

    public function test_customer_data_is_validated(): void
    {
        // Sao mais pedidos do que o limite por minuto deixa.
        $this->withoutMiddleware(ThrottleRequests::class);
        $cart = $this->cartWith();
        $refused = fn (array $overrides, string $field) => $this->pay($cart, $this->customer(3000, $overrides))
            ->assertRedirect('/finalizar')
            ->assertSessionHasErrors($field);

        $refused(['name' => ''], 'name');
        $refused(['email' => ''], 'email');
        $refused(['email' => 'ana'], 'email');
        $refused(['email' => 'ana@localhost'], 'email');
        $refused(['email' => 'ana@exemplo.1'], 'email');
        $refused(['phone' => ''], 'phone');
        $refused(['phone' => 'liga-me'], 'phone');
        $refused(['postal_code' => '1000'], 'postal_code');
        $refused(['postal_code' => '1000001'], 'postal_code');
        $refused(['nif' => '12345678'], 'nif');
        $refused(['nif' => '123456780'], 'nif');
        $refused(['line1' => ''], 'line1');
        $refused(['city' => ''], 'city');
        $refused(['terms' => '0'], 'terms');
        $refused(['expected_total_cents' => ''], 'expected_total_cents');

        $this->assertSame(0, Checkout::query()->count());

        $this->pay($cart, $this->customer(3000, ['nif' => '', 'line2' => '', 'postal_code' => '9500-123', 'city' => 'Ponta Delgada']))
            ->assertSessionHasNoErrors();
        $this->pay($cart, $this->customer(3000, ['postal_code' => '9000-001', 'city' => 'Funchal']))
            ->assertSessionHasNoErrors();

        $first = Checkout::query()->orderBy('id')->firstOrFail();
        $this->assertNull($first->customer['nif']);
        $this->assertNull($first->shipping_address['line2']);
        $this->assertSame('PT', $first->shipping_address['country']);
    }

    public function test_an_email_stripe_would_refuse_gets_the_normal_email_message(): void
    {
        $cart = $this->cartWith();

        $this->pay($cart, $this->customer(3000, ['email' => 'ana@localhost']))
            ->assertSessionHasErrors(['email' => trans('validation.email', ['attribute' => trans('store.attributes.email')])]);
    }

    public function test_the_errors_speak_the_language_of_the_page(): void
    {
        $cart = $this->cartWith();

        $this->holding($cart)->from('/en/finalizar')->post('/en/finalizar/pagar', $this->customer(3000, ['nif' => '123456780']))
            ->assertSessionHasErrors(['nif' => trans('store.checkout.errors.nif', [], 'en')]);
    }

    public function test_a_personal_code_is_confirmed_with_the_email(): void
    {
        PromoCode::factory()->create(['code' => 'PESSOAL', 'email' => 'ana@example.com', 'value' => 500]);
        $cart = $this->cartWith();
        $cart->update(['promo_code' => 'PESSOAL']);

        $this->pay($cart, $this->customer(3000, ['email' => 'outra@example.com']))
            ->assertRedirect('/finalizar')
            ->assertSessionHasErrors(['promo_code' => trans('store.cart.promo.wrong_email')]);
        $this->assertSame(0, Checkout::query()->count());
        $this->assertSame([], $this->stripe['sessions']);

        $this->pay($cart, $this->customer(2500))->assertSessionHasNoErrors();

        $checkout = Checkout::query()->firstOrFail();
        $this->assertSame(500, $checkout->discount_cents);
        $this->assertSame('PESSOAL', $checkout->promo_code);
        $this->assertSame(PromoCode::query()->where('code', 'PESSOAL')->value('id'), $checkout->promo_code_id);
        $this->assertSame(2500, $checkout->total_cents);

        $session = array_values($this->stripe['sessions'])[0];
        $this->assertSame(2500, $session['amount_total']);
        $coupon = $this->stripe['coupons'][$session['request']['discounts'][0]['coupon']];
        $this->assertSame('500', $coupon['amount_off']);
        $this->assertSame('PESSOAL', $coupon['name']);
        $this->assertSame("checkout-{$checkout->id}-coupon", $this->stripeCallsTo('POST', 'coupons')[0]['idempotency']);
    }

    public function test_confirming_the_email_only_quotes_the_cart(): void
    {
        PromoCode::factory()->create(['code' => 'PESSOAL', 'email' => 'ana@example.com', 'value' => 500]);
        $cart = $this->cartWith();
        $cart->update(['promo_code' => 'PESSOAL']);
        $updatedAt = $cart->fresh()?->updated_at;
        $this->stripeCalls = [];

        $this->holding($cart)->from('/finalizar')->post('/finalizar/codigo', ['email' => 'ana@example.com'])
            ->assertRedirect('/finalizar');
        $this->assertSame('applied', $this->flashed()['promo']['status']);
        $this->assertSame(2500, $this->flashed()['totalCents']);

        $this->holding($cart)->from('/finalizar')->post('/finalizar/codigo', ['email' => 'outra@example.com'])
            ->assertRedirect('/finalizar');
        $this->assertSame('refused', $this->flashed()['promo']['status']);
        $this->assertSame(3000, $this->flashed()['totalCents']);

        $this->holding($cart)->from('/finalizar')->post('/finalizar/codigo', ['email' => 'ana'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Checkout::query()->count());
        $this->assertSame(1, Cart::query()->count());
        $this->assertSame('PESSOAL', $cart->fresh()?->promo_code);
        $this->assertEquals($updatedAt, $cart->fresh()?->updated_at);
        $this->assertSame([], $this->stripeCalls);
    }

    public function test_a_personal_code_from_the_page_to_the_payment(): void
    {
        PromoCode::factory()->create(['code' => 'PESSOAL', 'email' => 'ana@example.com', 'value' => 500]);
        $cart = $this->cartWith();
        $cart->update(['promo_code' => 'PESSOAL']);

        // A pagina abre com o codigo a espera do email e o total sem desconto.
        $this->holding($cart)->get('/finalizar')->assertInertia(fn (Assert $page) => $page
            ->where('cart.promo.status', 'pending_email')
            ->where('cart.totalCents', 3000));

        // Sem o email nem se chega ao codigo.
        $this->pay($cart, $this->customer(3000, ['email' => '']))->assertSessionHasErrors('email');

        // O email confirma-o; o cliente paga o total que entao viu.
        $this->holding($cart)->from('/finalizar')->post('/finalizar/codigo', ['email' => 'ana@example.com']);
        $seen = $this->flashed()['totalCents'];
        $this->assertSame(2500, $seen);

        $this->pay($cart, $this->customer($seen))->assertSessionHasNoErrors();

        $this->assertSame(500, Checkout::query()->firstOrFail()->discount_cents);
        $this->assertSame(2500, array_values($this->stripe['sessions'])[0]['amount_total']);
    }

    public function test_a_total_the_customer_did_not_see_is_not_charged(): void
    {
        $cart = $this->cartWith(2400, 1);

        $this->holding($cart)->get('/finalizar')->assertInertia(fn (Assert $page) => $page
            ->where('cart.totalCents', 2850));

        $cart->items()->firstOrFail()->variant->update(['price_cents' => 2600]);
        $sessionsBefore = count($this->stripeCallsTo('POST', 'checkout/sessions'));

        $this->pay($cart, $this->customer(2850))
            ->assertRedirect('/finalizar')
            ->assertSessionHasErrors('cart');

        $this->assertSame(0, Checkout::query()->count());
        $this->assertCount($sessionsBefore, $this->stripeCallsTo('POST', 'checkout/sessions'));
    }

    public function test_stock_gone_since_the_cart_stops_the_payment(): void
    {
        $cart = $this->cartWith();
        $this->holding($cart)->get('/finalizar')->assertOk();

        $cart->items()->firstOrFail()->variant->update(['stock' => 1]);

        $this->pay($cart, $this->customer(3000))
            ->assertRedirect('/finalizar')
            ->assertSessionHasErrors('cart');

        $this->assertSame(0, Checkout::query()->count());
        $this->assertSame([], $this->stripe['sessions']);
    }

    public function test_paying_twice_leaves_one_live_session(): void
    {
        $cart = $this->cartWith();

        $this->pay($cart, $this->customer(3000))->assertSessionHasNoErrors();
        $this->pay($cart, $this->customer(3000))->assertSessionHasNoErrors();

        [$first, $second] = Checkout::query()->orderBy('id')->get()->all();

        $this->assertSame('expired', $first->status);
        $this->assertSame('expired', $this->stripe['sessions'][$first->stripe_session_id]['status']);
        $this->assertSame('open', $second->status);
        $this->assertSame('open', $this->stripe['sessions'][$second->stripe_session_id]['status']);
    }

    public function test_a_session_that_will_not_expire_and_is_still_open_stops_the_payment(): void
    {
        Exceptions::fake();
        $cart = $this->cartWith();
        $this->pay($cart, $this->customer(3000))->assertSessionHasNoErrors();
        $first = Checkout::query()->firstOrFail();

        // O expire falha (uma falha de rede, um 500) e a sessao continua
        // aberta no Stripe. O FakesStripe nao se mexe: e so este cliente que
        // falha o expire.
        $this->app->instance(StripeClient::class, new class extends StripeClient
        {
            public function post(string $path, array $data = [], ?string $idempotencyKey = null): array
            {
                if (str_ends_with($path, '/expire')) {
                    throw new StripeException('Stripe indisponivel.', 500);
                }

                return parent::post($path, $data, $idempotencyKey);
            }
        });

        $this->pay($cart, $this->customer(3000))
            ->assertRedirect('/finalizar')
            ->assertSessionHasErrors(['payment' => trans('store.checkout.errors.payment')]);

        $this->assertSame('open', $first->fresh()?->status);
        $this->assertSame(1, Checkout::query()->count());
        $this->assertCount(1, $this->stripe['sessions']);
        $this->assertSame('open', $this->stripe['sessions'][$first->stripe_session_id]['status']);
        Exceptions::assertReported(fn (StripeException $exception): bool => $exception->status === 500);
    }

    public function test_a_paid_session_sends_the_customer_to_that_purchase(): void
    {
        $cart = $this->cartWith();
        $this->pay($cart, $this->customer(3000));
        $first = Checkout::query()->firstOrFail();

        // Pagou (ou gerou a referencia) e voltou atras antes do aviso do Stripe.
        $this->stripe['sessions'][$first->stripe_session_id]['status'] = 'complete';

        $this->pay($cart, $this->customer(3000))->assertRedirect(url("/compra/{$first->token}"));

        $this->assertSame(1, Checkout::query()->count());
        $this->assertSame('open', $first->fresh()?->status);
        $this->assertCount(1, $this->stripe['sessions']);
    }

    public function test_when_stripe_fails_the_customer_can_try_again(): void
    {
        $cart = $this->cartWith();
        // O Stripe so aceita sessoes entre 30 minutos e 24 horas.
        config(['shop.checkout.session_minutes' => 10]);

        $this->pay($cart, $this->customer(3000))
            ->assertRedirect('/finalizar')
            ->assertSessionHasErrors(['payment' => 'Não foi possível abrir o pagamento. Tenta outra vez daqui a pouco.']);

        $this->assertSame('expired', Checkout::query()->firstOrFail()->status);
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame([], $this->stripe['sessions']);

        config(['shop.checkout.session_minutes' => 60]);

        $this->pay($cart, $this->customer(3000))->assertSessionHasNoErrors();
        $this->assertCount(1, $this->stripe['sessions']);
    }

    public function test_with_the_shop_off_the_checkout_does_not_exist(): void
    {
        $cart = $this->cartWith();
        app(SettingService::class)->set(StoreSettings::KEY_SHOP, 'off');

        $this->holding($cart)->get('/finalizar')->assertNotFound();
        $this->holding($cart)->post('/finalizar/pagar', $this->customer(3000))->assertNotFound();
        $this->holding($cart)->post('/finalizar/codigo', ['email' => 'ana@example.com'])->assertNotFound();

        $this->assertSame(0, Checkout::query()->count());
    }

    public function test_paying_is_throttled_per_ip(): void
    {
        $cart = $this->cartWith();

        for ($i = 0; $i < 10; $i++) {
            $this->pay($cart, $this->customer(3000, ['name' => '']))->assertSessionHasErrors('name');
        }

        $this->pay($cart, $this->customer(3000))->assertStatus(429);
    }

    public function test_paying_is_capped_per_day_per_ip(): void
    {
        $cart = $this->cartWith();

        // Dez por minuto, durante seis minutos: o limite por minuto nunca
        // dispara, o do dia sim.
        for ($minute = 0; $minute < 6; $minute++) {
            for ($i = 0; $i < 10; $i++) {
                $this->pay($cart, $this->customer(3000, ['name' => '']))->assertSessionHasErrors('name');
            }

            $this->travel(61)->seconds();
        }

        $this->pay($cart, $this->customer(3000))->assertStatus(429);
        $this->assertSame(0, Checkout::query()->count());
    }

    public function test_a_personal_code_the_page_did_not_confirm_shows_the_new_total(): void
    {
        PromoCode::factory()->create(['code' => 'PESSOAL', 'email' => 'ana@example.com', 'value' => 500]);
        $cart = $this->cartWith();
        $cart->update(['promo_code' => 'PESSOAL']);

        // O email veio do preenchimento automatico e o campo nunca perdeu o
        // foco: a pagina manda o total sem desconto.
        $this->pay($cart, $this->customer(3000))
            ->assertRedirect('/finalizar')
            ->assertSessionHasErrors(['cart' => trans('store.checkout.errors.total_changed')]);
        $this->assertSame(0, Checkout::query()->count());

        // O que o cliente ve a seguir: as contas com este email, com o desconto.
        $this->assertSame(2500, $this->flashed()['totalCents']);
        $this->assertSame('applied', $this->flashed()['promo']['status']);

        $this->pay($cart, $this->customer($this->flashed()['totalCents']))->assertSessionHasNoErrors();
        $this->assertSame(2500, Checkout::query()->firstOrFail()->total_cents);
    }

    public function test_a_changed_price_shows_the_new_total(): void
    {
        $cart = $this->cartWith(2400, 1);
        $cart->items()->firstOrFail()->variant->update(['price_cents' => 2600]);

        $this->pay($cart, $this->customer(2850))->assertSessionHasErrors('cart');

        $this->assertSame(2600, $this->flashed()['totalCents']);
    }

    public function test_a_paid_session_wins_over_a_changed_price(): void
    {
        $cart = $this->cartWith();
        $this->pay($cart, $this->customer(3000));
        $first = Checkout::query()->firstOrFail();
        $this->stripe['sessions'][$first->stripe_session_id]['status'] = 'complete';

        $cart->items()->firstOrFail()->variant->update(['price_cents' => 1800]);

        $this->pay($cart, $this->customer(3000))
            ->assertSessionHasNoErrors()
            ->assertRedirect(url("/compra/{$first->token}"));
        $this->assertSame(1, Checkout::query()->count());
    }

    /** Uma compra anterior deste carrinho, ainda aberta, com uma sessao que o fake nao tem. */
    private function lostSession(Cart $cart, string $sessionId, CarbonImmutable $expiresAt): Checkout
    {
        return Checkout::factory()->create([
            'cart_id' => $cart->id,
            'status' => 'open',
            'stripe_session_id' => $sessionId,
            'expires_at' => $expiresAt,
        ]);
    }

    public function test_a_session_of_the_other_mode_does_not_block_the_cart(): void
    {
        Exceptions::fake();
        $cart = $this->cartWith();
        // As chaves trocadas: a compra anterior abriu-se com a chave do outro
        // modo (o fake corre com uma chave de teste), e para esta chave a
        // sessao nao existe nem vai poder ser paga.
        $first = $this->lostSession($cart, 'cs_live_'.Str::random(20), now()->addMinutes(40));

        $this->pay($cart, $this->customer(3000))->assertSessionHasNoErrors();

        $this->assertSame('expired', $first->fresh()?->status);
        $second = Checkout::query()->orderByDesc('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('open', $this->stripe['sessions'][$second->stripe_session_id]['status']);
        Exceptions::assertReported(fn (StripeException $exception): bool => $exception->status === 404);
    }

    public function test_a_lost_session_past_its_deadline_does_not_block_the_cart(): void
    {
        $cart = $this->cartWith();
        $first = $this->lostSession($cart, 'cs_test_'.Str::random(20), now()->subMinute());

        $this->pay($cart, $this->customer(3000))->assertSessionHasNoErrors();

        $this->assertSame('expired', $first->fresh()?->status);
        $this->assertCount(1, $this->stripe['sessions']);
    }

    public function test_a_lost_session_that_may_still_be_paid_stops_the_payment(): void
    {
        Exceptions::fake();
        $cart = $this->cartWith();
        // O mesmo modo e ainda no prazo: nada prova que nao se possa pagar.
        $first = $this->lostSession($cart, 'cs_test_'.Str::random(20), now()->addMinutes(40));

        $this->pay($cart, $this->customer(3000))
            ->assertRedirect('/finalizar')
            ->assertSessionHasErrors(['payment' => 'Não foi possível abrir o pagamento. Tenta outra vez daqui a pouco.']);

        $this->assertSame('open', $first->fresh()?->status);
        $this->assertSame(1, Checkout::query()->count());
        $this->assertSame([], $this->stripe['sessions']);
        Exceptions::assertReported(fn (StripeException $exception): bool => $exception->status === 404);
    }

    public function test_a_session_id_or_key_of_an_unknown_kind_is_not_another_mode(): void
    {
        $stripe = app(StripeClient::class);

        $this->assertTrue($stripe->isOtherMode('cs_live_abc'));
        $this->assertFalse($stripe->isOtherMode('cs_test_abc'));
        $this->assertFalse($stripe->isOtherMode('cs_abc'));

        config(['stripe.secret' => 'rk_'.'live_'.str_repeat('x', 24)]);
        $this->assertTrue($stripe->isOtherMode('cs_test_abc'));
        $this->assertFalse($stripe->isOtherMode('cs_live_abc'));

        config(['stripe.secret' => 'chave-estranha']);
        $this->assertFalse($stripe->isOtherMode('cs_live_abc'));
    }

    public function test_a_session_stripe_cannot_read_stops_the_payment(): void
    {
        $cart = $this->cartWith();
        $this->pay($cart, $this->customer(3000));
        $first = Checkout::query()->firstOrFail();
        config(['stripe.secret' => 'sk_'.'test_outra']);

        $this->pay($cart, $this->customer(3000))->assertSessionHasErrors('payment');

        $this->assertSame('open', $first->fresh()?->status);
        $this->assertSame(1, Checkout::query()->count());
    }

    public function test_what_stripe_says_reaches_the_report_without_the_customer(): void
    {
        $cart = $this->cartWith();
        $customer = ['name' => 'Ana Silva', 'email' => 'Ana@Localhost', 'phone' => '912 345 678', 'nif' => null];
        $address = ['line1' => 'Rua das Flores 12', 'line2' => null, 'postal_code' => '1000-001', 'city' => 'Lisboa'];

        // O formulario ja recusa este email; aqui chega direto ao servico, e o
        // Stripe recusa-o repetindo-o na mensagem.
        try {
            app(CheckoutService::class)->start($cart, $customer, $address, 'pt', 3000);
            $this->fail('Devia ter falhado.');
        } catch (StripeException $exception) {
            $this->assertStringContainsString('Invalid email address', $exception->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('ana@localhost', $exception->getMessage());
            $this->assertInstanceOf(StripeException::class, $exception->getPrevious());
            $this->assertStringNotContainsStringIgnoringCase('ana@localhost', $exception->getPrevious()->getMessage());
        }

        $this->assertSame('expired', Checkout::query()->firstOrFail()->status);

        // E o que o Stripe disser noutra forma: o email noutras maiusculas, o
        // telefone com espacos, o NIF e a rua. O id da sessao fica, para o
        // registo continuar a servir.
        $sessionId = 'cs_test_a1B2c3D4e5F6g7H8i9J0';
        $echo = new StripeException(
            "Sessao {$sessionId}: ANA.SILVA@EXAMPLE.COM, tel. 912 345 678, NIF 123456789, RUA DAS  FLORES 12, ANA  SILVA.",
            400,
        );

        $redacted = $echo->withoutPersonalData([
            'ana.silva@example.com', '912345678', 'Ana Silva', '123456789', 'Rua das Flores 12', null, 'Lisboa', '1000-001',
        ]);

        foreach ([$redacted->getMessage(), $redacted->getPrevious()?->getMessage()] as $message) {
            $this->assertIsString($message);
            $this->assertStringNotContainsStringIgnoringCase('example.com', $message);
            $this->assertStringNotContainsString('912 345 678', $message);
            $this->assertStringNotContainsString('123456789', $message);
            $this->assertStringNotContainsStringIgnoringCase('flores', $message);
            $this->assertStringNotContainsStringIgnoringCase('silva', $message);
            $this->assertStringContainsString($sessionId, $message);
        }
        $this->assertSame(400, $redacted->status);
    }

    public function test_shipping_reaches_the_session(): void
    {
        $cart = $this->cartWith(2400, 1);

        $this->pay($cart, $this->customer(2850))->assertSessionHasNoErrors();

        $checkout = Checkout::query()->firstOrFail();
        $this->assertSame(450, $checkout->shipping_cents);
        $session = array_values($this->stripe['sessions'])[0];
        $this->assertSame('450', $session['request']['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount']);
        $this->assertSame('Portes', $session['request']['shipping_options'][0]['shipping_rate_data']['display_name']);
        $this->assertSame(2850, $session['amount_total']);
    }

    public function test_paying_without_a_cart_creates_nothing(): void
    {
        $this->cartWith();
        $this->stripeCalls = [];

        $this->from('/finalizar')->post('/finalizar/pagar', $this->customer(3000))
            ->assertRedirect('/finalizar')
            ->assertSessionHasErrors(['cart' => trans('store.checkout.errors.cart')]);

        $this->assertSame(0, Checkout::query()->count());
        $this->assertSame([], $this->stripeCalls);
    }

    public function test_old_checkouts_without_an_order_are_pruned(): void
    {
        $old = Checkout::factory()->create(['created_at' => now()->subDays(31)]);
        $recent = Checkout::factory()->create(['created_at' => now()->subDays(29)]);
        $bought = Checkout::factory()->create([
            'created_at' => now()->subDays(400),
            'order_id' => Order::factory()->create()->id,
            'status' => 'completed',
        ]);

        $this->artisan('model:prune', ['--model' => [Checkout::class]])->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNotNull($recent->fresh());
        $this->assertNotNull($bought->fresh());
    }
}
