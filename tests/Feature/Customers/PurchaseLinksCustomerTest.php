<?php

namespace Tests\Feature\Customers;

use App\Customers\CustomerDirectory;
use App\Mail\OrderConfirmationMail;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\SettingService;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Stripe\FakesStripe;
use Tests\TestCase;

/**
 * Cada compra paga fica ligada a um cliente: o que ja existe volta como esta,
 * o que nao existe nasce com os dados da compra, e a equipa nunca entra aqui.
 * A recusa de marketing so se desfaz por quem entrou na conta.
 */
class PurchaseLinksCustomerTest extends TestCase
{
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

    /** Um carrinho com um vaso grande a 15 € × 2. */
    private function cartWith(): Cart
    {
        $cart = Cart::factory()->create();
        $variant = Variant::factory()->for(Product::factory()->create(['name' => 'Vaso']))->create(['price_cents' => 1500, 'size_label' => 'G']);
        CartItem::factory()->for($cart)->for($variant)->state(['qty' => 2])->create();

        return $cart;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pay(Cart $cart, array $overrides = [], ?User $as = null): Checkout
    {
        if ($as !== null) {
            $this->actingAs($as, 'customer');
        }

        $this->withCookie((string) config('shop.cart.cookie'), $cart->token)
            ->from('/finalizar')
            ->post('/finalizar/pagar', [
                'name' => 'Ana Silva',
                'email' => 'ana@exemplo.pt',
                'phone' => '912 345 678',
                'nif' => '123456789',
                'line1' => 'Rua das Flores 12',
                'line2' => '2.º esq.',
                'postal_code' => '1000-001',
                'city' => 'Lisboa',
                'terms' => '1',
                'expected_total_cents' => 3000,
                ...$overrides,
            ])
            ->assertSessionHasNoErrors();

        return Checkout::query()->orderByDesc('id')->firstOrFail();
    }

    /**
     * Paga e o Stripe avisa: a encomenda nasce do aviso.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function buy(array $overrides = [], ?User $as = null): Order
    {
        $checkout = $this->pay($this->cartWith(), $overrides, $as);

        $session = $this->stripe['sessions'][(string) $checkout->stripe_session_id];
        unset($session['request'], $session['url']);
        $session = [...$session, 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_123'];

        $this->stripePaymentIntent('pi_123', ['latest_charge' => ['payment_method_details' => ['type' => 'card']]]);
        $this->send(['id' => 'evt_'.Str::random(16), 'object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => $session]])
            ->assertNoContent();

        return Order::query()->latest('id')->firstOrFail();
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

    public function test_a_guest_purchase_creates_a_customer_and_links_the_order(): void
    {
        $order = $this->buy();

        $customer = User::query()->where('email', 'ana@exemplo.pt')->sole();
        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame('Ana Silva', $customer->name);
        $this->assertSame('Rua das Flores 12', $customer->addresses()->sole()->line1);
        Mail::assertQueued(OrderConfirmationMail::class);
    }

    public function test_a_guest_purchase_links_to_an_existing_customer_regardless_of_case(): void
    {
        $existing = User::factory()->create(['email' => 'Ana@Exemplo.pt']);

        $order = $this->buy();

        $this->assertSame($existing->id, $order->user_id);
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_guest_purchase_never_changes_an_existing_customer(): void
    {
        $existing = User::factory()->create(['email' => 'ana@exemplo.pt', 'name' => 'Nome Antigo']);
        Address::factory()->for($existing)->create(['is_default' => true, 'line1' => 'Rua Antiga 1']);

        $this->buy();

        $existing->refresh();
        $this->assertSame('Nome Antigo', $existing->name);
        $this->assertSame('Rua Antiga 1', $existing->addresses()->sole()->line1);
    }

    public function test_a_guest_can_opt_out_but_never_opt_back_in(): void
    {
        $this->buy(['marketing_opt_out' => '1']);

        $customer = User::query()->where('email', 'ana@exemplo.pt')->sole();
        $this->assertNotNull($customer->marketing_opt_out_at);

        $this->buy();

        $this->assertNotNull($customer->refresh()->marketing_opt_out_at);
    }

    public function test_a_signed_in_purchase_saves_the_details_and_the_choice(): void
    {
        $customer = User::factory()->create(['email' => 'ana@exemplo.pt', 'name' => 'Nome Antigo', 'marketing_opt_out_at' => now()->subDay()]);
        Address::factory()->for($customer)->create(['is_default' => true, 'line1' => 'Rua Antiga 1']);

        $order = $this->buy([], $customer);

        $customer->refresh();
        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame('Ana Silva', $customer->name);
        $this->assertSame('Rua das Flores 12', $customer->addresses()->sole()->line1);
        $this->assertNull($customer->marketing_opt_out_at);
    }

    public function test_a_signed_in_purchase_can_opt_out(): void
    {
        $customer = User::factory()->create(['email' => 'ana@exemplo.pt']);

        $this->buy(['marketing_opt_out' => '1'], $customer);

        $this->assertNotNull($customer->refresh()->marketing_opt_out_at);
    }

    public function test_the_checkout_records_the_choice(): void
    {
        $this->assertFalse($this->pay($this->cartWith())->marketing_opt_out);

        Checkout::query()->delete();

        $this->assertTrue($this->pay($this->cartWith(), ['marketing_opt_out' => '1'])->marketing_opt_out);
    }

    public function test_a_purchase_with_a_staff_email_links_nobody(): void
    {
        User::factory()->admin()->create(['email' => 'ana@exemplo.pt']);

        $order = $this->buy();

        $this->assertNull($order->user_id);
        $this->assertSame(1, User::query()->count());
    }

    public function test_an_ambiguous_email_links_nobody_and_still_creates_the_order(): void
    {
        User::factory()->create()->forceFill(['email' => 'ana@exemplo.pt'])->save();
        User::factory()->create()->forceFill(['email' => 'ANA@exemplo.pt'])->save();

        $order = $this->buy();

        $this->assertNull($order->user_id);
        $this->assertSame(2, User::query()->count());
    }

    public function test_for_purchase_finds_a_customer_created_with_another_case_without_failing(): void
    {
        $existing = User::factory()->create();
        $existing->forceFill(['email' => 'Ana@Exemplo.pt'])->save();

        $found = app(CustomerDirectory::class)->forPurchase(
            'ana@exemplo.pt',
            ['name' => 'Ana', 'email' => 'ana@exemplo.pt', 'phone' => '912345678', 'nif' => null],
            ['name' => 'Ana', 'line1' => 'Rua 1', 'line2' => null, 'postalCode' => '1000-001', 'city' => 'Lisboa', 'country' => 'PT', 'phone' => '912345678'],
        );

        $this->assertTrue($existing->is($found));
        $this->assertSame(1, User::query()->count());
    }
}
