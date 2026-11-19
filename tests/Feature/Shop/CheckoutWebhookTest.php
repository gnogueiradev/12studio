<?php

namespace Tests\Feature\Shop;

use App\Alerts\AlertChannel;
use App\Mail\OrderConfirmationMail;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Checkout;
use App\Models\Color;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use App\Models\WebhookEvent;
use App\Services\SettingService;
use App\Services\StoreSettings;
use App\Stripe\Charge;
use App\Stripe\StripeCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Feature\Alerts\FakesDiscord;
use Tests\Feature\Stripe\FakesStripe;
use Tests\TestCase;

/**
 * Do pagamento a encomenda. O aviso do Stripe encontra o `checkout` e cria a
 * encomenda a partir do retrato congelado no "Pagar" — nunca do carrinho —, e
 * o pagamento marca-a paga pelo caminho de sempre. O pior que pode acontecer
 * aqui e um cliente pagar e nao haver encomenda, ou haver duas: e sobre isso
 * que estes testes sao.
 */
class CheckoutWebhookTest extends TestCase
{
    use FakesDiscord;
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
        Mail::fake();
        $this->fakeStripe();
        app(SettingService::class)->set(StoreSettings::KEY_SHOP, 'on');
        RateLimiter::clear('cart');
        RateLimiter::clear('checkout');
    }

    /**
     * @param  array<string, string>  $personalization
     */
    private function addTo(Cart $cart, Product $product, int $priceCents, int $qty, array $personalization = [], string $label = 'G'): Variant
    {
        $variant = Variant::factory()->for($product)->create(['price_cents' => $priceCents, 'size_label' => $label]);
        $item = CartItem::factory()->for($cart)->for($variant)->state(['qty' => $qty]);
        $personalization === [] ? $item->create() : $item->personalised($personalization)->create();

        return $variant;
    }

    /** Um carrinho com um vaso grande a 15 € × 2. */
    private function cartWith(): Cart
    {
        $cart = Cart::factory()->create();
        $this->addTo($cart, Product::factory()->create(['name' => 'Vaso']), 1500, 2);

        return $cart;
    }

    private function personalised(): Product
    {
        return Product::factory()->create([
            'name' => 'Porta-chaves',
            'personalization_fields' => [['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true]],
            'personalization_surcharge_cents' => 300,
        ]);
    }

    /** O "Pagar" a serio: o retrato e a sessao sao os que a producao escreve. */
    private function pay(Cart $cart, int $expectedTotalCents): Checkout
    {
        $this->withCookie((string) config('shop.cart.cookie'), $cart->token)
            ->from('/finalizar')
            ->post('/finalizar/pagar', [
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
            ])
            ->assertSessionHasNoErrors();

        return Checkout::query()->orderByDesc('id')->firstOrFail();
    }

    /**
     * A sessao do Stripe deste checkout, como chega no aviso.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function stripeSession(Checkout $checkout, array $overrides = []): array
    {
        $session = $this->stripe['sessions'][(string) $checkout->stripe_session_id];
        unset($session['request'], $session['url']);

        return [...$session, 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_123', ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function event(string $type, array $object): array
    {
        return ['id' => 'evt_'.Str::random(16), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return TestResponse<Response>
     */
    private function send(array $event): TestResponse
    {
        $body = (string) json_encode($event);
        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", self::stripeWebhookSecret());

        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function onlyOrder(): Order
    {
        $this->assertSame(1, Order::query()->count());

        return Order::query()->firstOrFail();
    }

    public function test_a_paid_session_creates_the_order_paid(): void
    {
        PromoCode::factory()->create(['code' => 'BEMVINDO', 'value' => 500]);
        $cart = Cart::factory()->create(['promo_code' => 'BEMVINDO']);
        $variant = $this->addTo($cart, $this->personalised(), 900, 2, ['nome' => 'Ana']);
        $this->stripePaymentIntent('pi_123', ['latest_charge' => ['payment_method_details' => ['type' => 'card']]]);

        // (9 € + 3 € de nome) × 2 = 24 €, com portes por ficar abaixo dos 25 €.
        $checkout = $this->pay($cart, 2350);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout)))->assertNoContent();

        $order = $this->onlyOrder();
        $this->assertSame('website', $order->sales_channel);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('ready_to_ship', $order->status);
        $this->assertSame('card', $order->payment_method);
        // Quem compra sem conta fica com uma: a encomenda liga-se a ela.
        $this->assertSame(User::query()->where('email', 'ana@example.com')->sole()->id, $order->user_id);
        $this->assertNull($order->created_by_user_id);
        $this->assertSame('Ana Silva', $order->customer_name);
        $this->assertSame('ana@example.com', $order->email);
        $this->assertSame('912 345 678', $order->phone);
        $this->assertSame('123456789', $order->nif);
        $this->assertSame($checkout->shipping_address, $order->shipping_address);
        $this->assertSame(2400, $order->subtotal_cents);
        $this->assertSame(450, $order->shipping_cents);
        $this->assertSame(500, $order->discount_cents);
        $this->assertSame(0, $order->adjustment_cents);
        $this->assertSame(2350, $order->total_cents);
        $this->assertSame('BEMVINDO', $order->promo_code);
        $this->assertSame($checkout->promo_code_id, $order->promo_code_id);
        $this->assertSame((string) $checkout->stripe_session_id, $order->stripe_session_id);
        $this->assertSame('pi_123', $order->stripe_payment_intent_id);
        $this->assertSame(64, strlen($order->guest_access_token));

        $item = $order->items()->sole();
        $this->assertSame($variant->id, $item->variant_id);
        $this->assertSame('Porta-chaves', $item->product_name);
        $this->assertSame('G', $item->variant_label);
        $this->assertSame($variant->sku, $item->sku);
        $this->assertSame(2, $item->qty);
        $this->assertSame(900, $item->unit_price_cents);
        $this->assertSame(300, $item->personalization_surcharge_cents);
        $this->assertSame(2400, $item->line_total_cents);
        $this->assertSame(900, $item->catalog_unit_price_cents);
        $this->assertNull($item->price_override_reason);
        $this->assertSame(['nome' => 'Ana'], $item->personalization);
        $this->assertSame('in_stock', $item->fulfillment_mode);
        $this->assertSame('not_required', $item->production_status);

        $checkout->refresh();
        $this->assertSame('completed', $checkout->status);
        $this->assertSame($order->id, $checkout->order_id);
        // Os dados pessoais ficam so na encomenda.
        $this->assertNull($checkout->customer);
        $this->assertNull($checkout->shipping_address);

        Mail::assertQueued(OrderConfirmationMail::class, fn (OrderConfirmationMail $mail): bool => $mail->hasTo('ana@example.com') && $mail->order->is($order));
        Mail::assertQueuedCount(1);

        $this->assertSame(8, $variant->fresh()?->stock);
        $this->assertTrue(StockMovement::query()->where('order_id', $order->id)->where('reason', 'sale')->where('delta', -2)->exists());

        $this->assertTrue($order->statusHistories()->where('note', 'Compra na loja online.')->exists());
        $this->assertTrue($order->statusHistories()->where('note', 'like', '%Pago na loja online.')->exists());
        $this->assertFalse($order->statusHistories()->where('note', 'like', '%Pago pelo link%')->exists());
    }

    public function test_the_order_follows_the_frozen_purchase_not_the_cart(): void
    {
        $cart = Cart::factory()->create();
        $vase = $this->addTo($cart, Product::factory()->create(['name' => 'Vaso']), 1500, 2);
        $bowl = $this->addTo($cart, Product::factory()->create(['name' => 'Taça']), 1000, 1, [], 'P');
        $checkout = $this->pay($cart, 4000);

        // Depois do "Pagar", antes do aviso: o preco muda, a quantidade muda e
        // uma linha sai do carrinho.
        $vase->update(['price_cents' => 2000]);
        $cart->items()->where('variant_id', $vase->id)->update(['qty' => 5]);
        $cart->items()->where('variant_id', $bowl->id)->delete();

        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout)))->assertNoContent();

        $order = $this->onlyOrder();
        $this->assertSame(4000, $order->subtotal_cents);
        $this->assertSame(4000, $order->total_cents);
        $this->assertSame('paid', $order->payment_status);

        $items = $order->items()->orderBy('id')->get();
        $this->assertCount(2, $items);

        $this->assertSame(['Vaso', 'G', 2, 1500, 3000], [$items[0]->product_name, $items[0]->variant_label, $items[0]->qty, $items[0]->unit_price_cents, $items[0]->line_total_cents]);
        $this->assertSame(2000, $items[0]->catalog_unit_price_cents);
        $this->assertSame('Preço no momento da compra', $items[0]->price_override_reason);

        $this->assertSame(['Taça', 'P', 1, 1000, 1000], [$items[1]->product_name, $items[1]->variant_label, $items[1]->qty, $items[1]->unit_price_cents, $items[1]->line_total_cents]);
        $this->assertNull($items[1]->price_override_reason);

        $this->assertSame(8, $vase->fresh()?->stock);
        $this->assertSame(9, $bowl->fresh()?->stock);
    }

    public function test_the_cart_is_emptied(): void
    {
        PromoCode::factory()->create(['code' => 'BEMVINDO', 'value' => 500]);
        $cart = $this->cartWith();
        $cart->update(['promo_code' => 'BEMVINDO']);
        $checkout = $this->pay($cart, 2500);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout)))->assertNoContent();

        $this->assertSame(0, $cart->items()->count());
        $this->assertNull($cart->fresh()?->promo_code);
    }

    public function test_a_cart_pruned_before_the_payment_does_not_stop_the_order(): void
    {
        $cart = $this->cartWith();
        $checkout = $this->pay($cart, 3000);
        $cart->items()->delete();
        $cart->delete();

        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout)))->assertNoContent();

        $this->assertSame('paid', $this->onlyOrder()->payment_status);
        $this->assertSame('completed', $checkout->fresh()?->status);
    }

    public function test_a_purchased_line_says_its_colour_material_and_size(): void
    {
        $cart = Cart::factory()->create();
        $product = Product::factory()->create(['name' => 'Vaso']);
        $color = Color::factory()->create(['name' => 'Azul', 'name_i18n' => ['en' => 'Blue']]);
        $material = Material::factory()->create(['name' => 'PLA mate', 'name_i18n' => ['en' => 'Matte PLA']]);
        $blue = Variant::factory()->for($product)->create(['price_cents' => 1500, 'size_label' => 'G', 'color_id' => $color->id, 'material_id' => $material->id]);
        CartItem::factory()->for($cart)->for($blue)->create(['qty' => 1]);
        // So com tamanho: fica so o tamanho.
        $this->addTo($cart, $product, 1500, 1, [], 'P');

        // Pago em ingles, guardado em portugues (como o resto da encomenda).
        $this->withCookie((string) config('shop.cart.cookie'), $cart->token)
            ->from('/en/finalizar')
            ->post('/en/finalizar/pagar', [
                'name' => 'Ana Silva', 'email' => 'ana@example.com', 'phone' => '912 345 678', 'nif' => '',
                'line1' => 'Rua das Flores 12', 'line2' => '', 'postal_code' => '1000-001', 'city' => 'Lisboa',
                'terms' => '1', 'expected_total_cents' => 3000,
            ])
            ->assertSessionHasNoErrors();
        $checkout = Checkout::query()->sole();

        $this->assertSame(['Azul · PLA mate · G', 'P'], array_column($checkout->items_snapshot, 'variant_label'));
        $session = array_values($this->stripe['sessions'])[0];
        $this->assertStringContainsString('1× Vaso (Azul · PLA mate · G); 1× Vaso (P)', $session['request']['custom_text']['submit']['message']);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout)))->assertNoContent();

        $this->assertSame(['Azul · PLA mate · G', 'P'], $this->onlyOrder()->items()->orderBy('id')->pluck('variant_label')->all());
    }

    public function test_a_snapshot_without_the_optional_keys_still_completes(): void
    {
        $cart = $this->cartWith();
        $variant = $cart->items()->firstOrFail()->variant;
        $checkout = $this->pay($cart, 3000);

        // Um retrato de antes de um deploy que mude a forma das linhas: so
        // as chaves obrigatorias (e a variante).
        $checkout->update(['items_snapshot' => array_map(
            fn (array $line): array => array_intersect_key($line, array_flip(['variant_id', 'product_name', 'qty', 'unit_price_cents', 'line_total_cents'])),
            $checkout->items_snapshot,
        )]);
        $checkout->refresh();

        $charge = Charge::fromCheckout($checkout, 'https://12studio.test/compra', 'https://12studio.test/finalizar');
        $this->assertSame('Vaso', $charge->lines[0]->name);
        $this->assertSame(3000, $charge->lines[0]->lineTotalCents);

        $this->get("/compra/{$checkout->token}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('purchase.lines.0.name', 'Vaso')
            ->where('purchase.lines.0.label', null));

        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout)))->assertNoContent();

        $item = $this->onlyOrder()->items()->sole();
        $this->assertSame('paid', $this->onlyOrder()->payment_status);
        $this->assertNull($item->variant_label);
        $this->assertSame($variant->sku, $item->sku);
        $this->assertSame(0, $item->personalization_surcharge_cents);
        $this->assertSame($variant->product->vat_rate, $item->vat_rate);
        $this->assertNull($item->personalization);
        $this->assertNull($item->image_url);
        $this->assertSame('in_stock', $item->fulfillment_mode);
        $this->assertSame(8, $variant->fresh()?->stock);
    }

    public function test_the_same_event_twice_creates_one_order(): void
    {
        $checkout = $this->pay($this->cartWith(), 3000);
        $event = $this->event('checkout.session.completed', $this->stripeSession($checkout));

        $this->send($event)->assertNoContent();
        $this->send($event)->assertNoContent();
        // O mesmo aviso, reenviado com outro id.
        $this->send([...$event, 'id' => 'evt_'.Str::random(16)])->assertNoContent();

        $order = $this->onlyOrder();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(1, OrderItem::query()->count());
        $this->assertSame(1, $order->statusHistories()->where('note', 'like', '%Pago na loja online.')->count());
        $this->assertSame(1, StockMovement::query()->where('order_id', $order->id)->count());
        Mail::assertQueuedCount(1);
    }

    public function test_two_different_events_for_one_session_create_one_order(): void
    {
        $checkout = $this->pay($this->cartWith(), 3000);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout, ['payment_status' => 'unpaid'])))->assertNoContent();
        $this->send($this->event('checkout.session.async_payment_succeeded', $this->stripeSession($checkout)))->assertNoContent();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame('paid', $this->onlyOrder()->payment_status);
        $this->assertSame(1, StockMovement::query()->count());
        Mail::assertQueuedCount(1);
    }

    public function test_multibanco_waits_and_then_pays(): void
    {
        $checkout = $this->pay($this->cartWith(), 3000);
        $this->stripePaymentIntent('pi_123', ['latest_charge' => ['payment_method_details' => ['type' => 'multibanco']]]);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout, ['payment_status' => 'unpaid'])))->assertNoContent();

        $order = $this->onlyOrder();
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('pending_payment', $order->status);
        $this->assertSame((string) $checkout->stripe_session_id, $order->stripe_session_id);
        $this->assertSame('pi_123', $order->stripe_payment_intent_id);
        $this->assertTrue($order->statusHistories()->where('note', 'Stripe: pagamento iniciado, a aguardar confirmação.')->exists());
        $this->assertSame('completed', $checkout->fresh()?->status);
        Mail::assertQueued(OrderConfirmationMail::class);

        $this->send($this->event('checkout.session.async_payment_succeeded', $this->stripeSession($checkout)))->assertNoContent();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('multibanco', $order->payment_method);
        $this->assertTrue($order->statusHistories()->where('note', 'like', '%Pago na loja online.')->exists());
    }

    public function test_a_failed_multibanco_leaves_the_order_unpaid_and_payable_by_link(): void
    {
        $checkout = $this->pay($this->cartWith(), 3000);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout, ['payment_status' => 'unpaid'])))->assertNoContent();
        $this->send($this->event('checkout.session.async_payment_failed', $this->stripeSession($checkout, ['payment_status' => 'unpaid'])))->assertNoContent();

        $order = $this->onlyOrder();
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('pending_payment', $order->status);
        $this->assertNull($order->stripe_session_id);
        $this->assertNull($order->stripe_payment_intent_id);
        $this->assertTrue(StripeCheckout::payable($order));
        $this->assertSame($order->id, $checkout->fresh()?->order_id);
    }

    public function test_a_failed_payment_without_an_order_creates_none(): void
    {
        $checkout = $this->pay($this->cartWith(), 3000);

        $this->send($this->event('checkout.session.async_payment_failed', $this->stripeSession($checkout, ['payment_status' => 'unpaid'])))->assertNoContent();

        $this->assertSame(0, Order::query()->count());
        $checkout->refresh();
        $this->assertSame('open', $checkout->status);
        $this->assertNull($checkout->order_id);
        Mail::assertNothingQueued();
    }

    public function test_stock_gone_meanwhile_still_creates_the_order_with_a_flag(): void
    {
        $cart = $this->cartWith();
        $variant = $cart->items()->firstOrFail()->variant;
        $checkout = $this->pay($cart, 3000);
        $variant->update(['stock' => 0]);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout)))->assertNoContent();

        $order = $this->onlyOrder();
        $this->assertSame('paid', $order->payment_status);
        $this->assertTrue($order->stock_issue);
        $this->assertSame(0, $variant->fresh()?->stock);
        $this->assertSame(0, StockMovement::query()->where('order_id', $order->id)->count());
    }

    public function test_a_made_to_order_line_goes_to_production_without_touching_stock(): void
    {
        $cart = Cart::factory()->create();
        $product = Product::factory()->create(['name' => 'Vaso', 'fulfillment_mode' => 'in_stock', 'allow_backorder' => true]);
        $variant = $this->addTo($cart, $product, 1500, 3);
        $variant->update(['stock' => 1]);

        // Pede 3 e so ha 1: a linha inteira e feita por encomenda.
        $checkout = $this->pay($cart, 4500);
        $this->assertSame('made_to_order', $checkout->items_snapshot[0]['fulfillment_mode']);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout)))->assertNoContent();

        $order = $this->onlyOrder();
        $item = $order->items()->sole();
        $this->assertSame('made_to_order', $item->fulfillment_mode);
        $this->assertSame('awaiting_production', $item->production_status);
        $this->assertSame('in_production', $order->status);
        $this->assertFalse($order->stock_issue);
        $this->assertSame(1, $variant->fresh()?->stock);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_the_code_is_redeemed_when_paid(): void
    {
        $code = PromoCode::factory()->create(['code' => 'BEMVINDO', 'value' => 500]);
        $cart = $this->cartWith();
        $cart->update(['promo_code' => 'BEMVINDO']);
        $checkout = $this->pay($cart, 2500);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout)))->assertNoContent();

        $order = $this->onlyOrder();
        $this->assertSame(1, $code->fresh()?->uses_count);
        $this->assertTrue(PromoCodeRedemption::query()
            ->where('promo_code_id', $code->id)
            ->where('order_id', $order->id)
            ->where('email', 'ana@example.com')
            ->where('discount_cents', 500)
            ->exists());
    }

    public function test_a_session_that_is_not_the_checkouts_own_touches_nothing_and_is_reported(): void
    {
        Exceptions::fake();
        $cart = $this->cartWith();
        $checkout = $this->pay($cart, 3000);
        $other = 'cs_test_'.Str::random(20);

        // A referencia e a metadata dizem este checkout, mas a sessao nao e a
        // que ele guardou.
        $session = $this->stripeSession($checkout, ['id' => $other]);
        $this->send($this->event('checkout.session.completed', $session))->assertNoContent();
        $this->send($this->event('checkout.session.async_payment_succeeded', $session))->assertNoContent();

        $this->assertSame(0, Order::query()->count());
        $checkout->refresh();
        $this->assertSame('open', $checkout->status);
        $this->assertNull($checkout->order_id);
        $this->assertSame(1, $cart->items()->count());
        Mail::assertNothingQueued();
        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), $other)
            && str_contains($exception->getMessage(), "checkout {$checkout->id}")
            && ! str_contains($exception->getMessage(), 'ana@example.com'));
    }

    public function test_an_unknown_checkout_touches_nothing(): void
    {
        Exceptions::fake();
        $cart = $this->cartWith();
        $checkout = $this->pay($cart, 3000);
        $missing = $checkout->id + 100;

        $sessions = [
            // Um checkout que nao existe.
            $this->stripeSession($checkout, ['client_reference_id' => "checkout-{$missing}", 'metadata' => ['checkout_id' => (string) $missing]]),
            // A referencia e a metadata nao batem certo.
            $this->stripeSession($checkout, ['client_reference_id' => "checkout-{$missing}"]),
            $this->stripeSession($checkout, ['client_reference_id' => (string) $checkout->id]),
            $this->stripeSession($checkout, ['metadata' => ['checkout_id' => (string) $missing]]),
            // Sem metadata nenhuma (criada a mao no Dashboard).
            $this->stripeSession($checkout, ['metadata' => []]),
        ];

        foreach ($sessions as $session) {
            $this->send($this->event('checkout.session.completed', $session))->assertNoContent();
            $this->send($this->event('checkout.session.async_payment_succeeded', $session))->assertNoContent();
        }

        $this->assertSame(0, Order::query()->count());
        $checkout->refresh();
        $this->assertSame('open', $checkout->status);
        $this->assertNull($checkout->order_id);
        $this->assertSame(1, $cart->items()->count());
        Mail::assertNothingQueued();
        // Um pagamento sem encomenda: o dono tem de o ver.
        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), (string) $checkout->stripe_session_id)
            && str_contains($exception->getMessage(), "checkout {$missing}"));
    }

    public function test_an_expired_checkout_that_is_paid_still_gets_its_order(): void
    {
        $cart = $this->cartWith();
        $first = $this->pay($cart, 3000);
        $second = $this->pay($cart, 3000);
        $this->assertSame('expired', $first->fresh()?->status);

        // O cliente pagou a primeira sessao mesmo antes de ela expirar.
        $this->send($this->event('checkout.session.completed', $this->stripeSession($first)))->assertNoContent();

        $order = $this->onlyOrder();
        $this->assertSame('paid', $order->payment_status);
        $first->refresh();
        $this->assertSame('completed', $first->status);
        $this->assertSame($order->id, $first->order_id);
        $this->assertNull($second->fresh()?->order_id);
    }

    public function test_an_order_clears_the_personal_data_of_the_expired_leftovers_of_its_cart(): void
    {
        $cart = $this->cartWith();
        $first = $this->pay($cart, 3000);
        $second = $this->pay($cart, 3000);
        $stranger = $this->pay($this->cartWith(), 3000);
        $this->assertSame('expired', $first->fresh()?->status);
        $this->assertNotNull($first->fresh()?->customer);
        // So conta o prazo da sessao: a primeira ja passou dele.
        Checkout::query()->whereKey($first->id)->update(['expires_at' => now()->subMinute()]);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($second)))->assertNoContent();

        // A primeira e sobra da mesma tentativa de compra: nao guarda dados.
        $first->refresh();
        $this->assertNull($first->customer);
        $this->assertNull($first->shipping_address);
        $this->assertSame('expired', $first->status);
        $this->assertNull($first->order_id);

        // O de outro carrinho nao se mexe.
        $stranger->refresh();
        $this->assertSame('open', $stranger->status);
        $this->assertSame('Ana Silva', $stranger->customer['name'] ?? null);
        $this->assertNotNull($stranger->shipping_address);
    }

    public function test_an_open_leftover_past_its_session_life_loses_its_personal_data_too(): void
    {
        $cart = $this->cartWith();
        $first = $this->pay($cart, 3000);
        $second = $this->pay($cart, 3000);
        // Aberto na base, mas com a sessao ja passada do prazo.
        Checkout::query()->whereKey($first->id)->update(['status' => 'open', 'expires_at' => now()->subMinute()]);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($second)))->assertNoContent();

        $first->refresh();
        $this->assertNull($first->customer);
        $this->assertNull($first->shipping_address);
        $this->assertSame('open', $first->status);
    }

    public function test_a_leftover_whose_session_can_still_be_paid_keeps_its_data_and_a_late_payment_makes_a_whole_order(): void
    {
        $cart = $this->cartWith();
        $first = $this->pay($cart, 3000);
        $second = $this->pay($cart, 3000);

        // A primeira paga-se primeiro; a segunda ainda esta dentro da vida da
        // sessao e pode ser paga: sem os dados a encomenda nasceria vazia.
        $this->send($this->event('checkout.session.completed', $this->stripeSession($first)))->assertNoContent();

        $second->refresh();
        $this->assertSame('open', $second->status);
        $this->assertSame('ana@example.com', $second->customer['email'] ?? null);
        $this->assertNotNull($second->shipping_address);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($second)))->assertNoContent();

        $late = Order::query()->where('stripe_session_id', $second->stripe_session_id)->sole();
        $this->assertSame('Ana Silva', $late->customer_name);
        $this->assertSame('ana@example.com', $late->email);
        $this->assertSame('Rua das Flores 12', $late->shipping_address['line1'] ?? null);
        $this->assertNull($second->fresh()?->customer);
    }

    public function test_an_expired_leftover_still_inside_its_session_life_keeps_its_data_and_a_late_payment_makes_a_whole_order(): void
    {
        $cart = $this->cartWith();
        $first = $this->pay($cart, 3000);
        $second = $this->pay($cart, 3000);
        $this->assertSame('expired', $first->fresh()?->status);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($second)))->assertNoContent();

        // Dada por expirada por nos, mas o Stripe ainda a pode cobrar.
        $first->refresh();
        $this->assertSame('expired', $first->status);
        $this->assertSame('ana@example.com', $first->customer['email'] ?? null);
        $this->assertNotNull($first->shipping_address);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($first)))->assertNoContent();

        $late = Order::query()->where('stripe_session_id', $first->stripe_session_id)->sole();
        $this->assertSame('Ana Silva', $late->customer_name);
        $this->assertSame('ana@example.com', $late->email);
        $this->assertSame('Rua das Flores 12', $late->shipping_address['line1'] ?? null);
        $this->assertFalse($late->statusHistories()->where('note', 'like', 'Dados do cliente em falta%')->exists());
    }

    public function test_a_late_payment_for_a_cleared_leftover_still_makes_an_order_and_says_the_data_is_missing(): void
    {
        Exceptions::fake();
        $cart = $this->cartWith();
        $first = $this->pay($cart, 3000);
        $second = $this->pay($cart, 3000);
        Checkout::query()->whereKey($first->id)->update(['expires_at' => now()->subMinute()]);
        $this->send($this->event('checkout.session.completed', $this->stripeSession($second)))->assertNoContent();
        $this->assertNull($first->fresh()?->customer);
        Mail::assertQueuedCount(1);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($first)))->assertNoContent();

        $this->assertSame(2, Order::query()->count());
        $this->assertSame('completed', $first->fresh()?->status);

        $late = Order::query()->where('stripe_session_id', $first->stripe_session_id)->sole();
        $this->assertTrue($late->statusHistories()
            ->where('note', 'Dados do cliente em falta: confirma-os no pagamento, no Dashboard do Stripe.')
            ->exists());

        // Dito ao dono sem dados pessoais, e sem email para um endereco vazio.
        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), "compra {$first->id}")
            && str_contains($exception->getMessage(), (string) $first->stripe_session_id)
            && ! str_contains($exception->getMessage(), 'ana@example.com'));
        Mail::assertQueuedCount(1);
    }

    public function test_a_busy_checkout_fails_the_webhook_so_stripe_retries(): void
    {
        Exceptions::fake();
        $checkout = $this->pay($this->cartWith(), 3000);
        $event = $this->event('checkout.session.completed', $this->stripeSession($checkout));
        $lock = Cache::lock("shop-complete:{$checkout->id}", 30);
        $this->assertTrue($lock->get());

        $this->send($event)->assertStatus(500);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame('open', $checkout->fresh()?->status);

        $lock->release();

        $this->send($event)->assertNoContent();
        $this->assertSame('paid', $this->onlyOrder()->payment_status);
    }

    public function test_the_payment_arriving_before_the_completed_event_creates_one_paid_order(): void
    {
        $checkout = $this->pay($this->cartWith(), 3000);

        // O Stripe nao garante a ordem: o "pago" do Multibanco chega antes do
        // "referencia gerada".
        $this->send($this->event('checkout.session.async_payment_succeeded', $this->stripeSession($checkout)))->assertNoContent();
        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout, ['payment_status' => 'unpaid'])))->assertNoContent();

        $order = $this->onlyOrder();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('ready_to_ship', $order->status);
        $this->assertFalse($order->statusHistories()->where('note', 'like', '%a aguardar confirmação%')->exists());
        Mail::assertQueuedCount(1);
    }

    public function test_a_checkout_pointing_to_a_missing_order_creates_none(): void
    {
        Exceptions::fake();
        $checkout = $this->pay($this->cartWith(), 3000);
        $checkout->update(['order_id' => 999, 'status' => 'completed']);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout)))->assertNoContent();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(999, $checkout->fresh()?->order_id);
        Mail::assertNothingQueued();
        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'encomenda 999, que já não existe'));
    }

    public function test_the_stock_alert_says_whether_the_purchase_is_paid(): void
    {
        $this->fakeDiscord();

        $numbers = [];

        // Cartao (pago na hora) e Multibanco (referencia por pagar).
        foreach (['paid' => 'e está paga', 'unpaid' => 'com o pagamento pendente'] as $paymentStatus => $expected) {
            $cart = $this->cartWith();
            $checkout = $this->pay($cart, 3000);
            $cart->items()->firstOrFail()->variant->update(['stock' => 0]);

            $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout, ['payment_status' => $paymentStatus])))->assertNoContent();

            $order = Order::query()->latest('id')->firstOrFail();
            $this->assertSame($order->id, $checkout->fresh()?->order_id);
            $numbers[$paymentStatus] = $order->order_number;

            $embed = $this->alertEmbed(AlertChannel::ORDERS, "Vendida sem stock — {$order->order_number}");
            $this->assertStringContainsString("A venda já aconteceu {$expected}, mas o stock não chega para tudo.", (string) $embed['description']);
        }

        // Pela ordem em que aconteceu: nova, sem stock, paga.
        $paid = $numbers['paid'];
        $titles = array_values(array_filter(
            $this->alertTitles(AlertChannel::ORDERS),
            fn (string $title): bool => str_contains($title, $paid) && preg_match('/Nova encomenda|Vendida sem stock|Pagamento registado/', $title) === 1,
        ));
        $this->assertSame(["🛒 Nova encomenda {$paid}", "⚠️ Vendida sem stock — {$paid}", "💶 Pagamento registado — {$paid}"], $titles);
    }

    public function test_the_payment_link_path_still_works(): void
    {
        $order = Order::factory()->create(['subtotal_cents' => 3000, 'shipping_cents' => 0, 'total_cents' => 3000]);
        OrderItem::factory()->for($order)->create(['unit_price_cents' => 3000, 'qty' => 1, 'line_total_cents' => 3000]);

        $this->send($this->event('checkout.session.completed', [
            'id' => 'cs_test_'.Str::random(10),
            'object' => 'checkout.session',
            'client_reference_id' => (string) $order->id,
            'metadata' => ['order_id' => (string) $order->id],
            'payment_status' => 'paid',
            'amount_total' => 3000,
            'payment_intent' => 'pi_link',
        ]))->assertNoContent();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(1, Order::query()->count());
        $this->assertTrue($order->statusHistories()->where('note', 'like', '%Pago pelo link do Stripe.')->exists());
        $this->assertSame(0, Checkout::query()->whereNotNull('order_id')->count());
        Mail::assertNothingQueued();
    }

    public function test_the_backoffice_shows_the_purchase_as_frozen(): void
    {
        PromoCode::factory()->create(['code' => 'BEMVINDO', 'value' => 500]);
        $cart = Cart::factory()->create(['promo_code' => 'BEMVINDO']);
        $this->addTo($cart, $this->personalised(), 900, 2, ['nome' => 'Ana']);
        $checkout = $this->pay($cart, 2350);

        $this->send($this->event('checkout.session.completed', $this->stripeSession($checkout)))->assertNoContent();
        $order = $this->onlyOrder();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.encomendas.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/encomendas/show')
                ->where('order.salesChannel', 'website')
                ->where('order.items.0.qty', 2)
                ->where('order.items.0.unitPriceCents', 900)
                ->where('order.items.0.personalizationSurchargeCents', 300)
                ->where('order.items.0.lineTotalCents', 2400)
                ->where('order.items.0.personalization.0.value', 'Ana')
                // O que a pagina soma ao vivo quando se edita o ajuste:
                // subtotal - desconto + portes (+ ajuste) = total.
                ->where('order.subtotalCents', 2400)
                ->where('order.discountCents', 500)
                ->where('order.shippingCents', 450)
                ->where('order.adjustmentCents', 0)
                ->where('order.totalCents', 2350));
    }
}
