<?php

namespace Tests\Feature\Shop;

use App\Mail\CustomerLoginLinkMail;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\SettingService;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Stripe\FakesStripe;
use Tests\TestCase;

/**
 * O /compra/{token}, onde o Stripe devolve o cliente. Ele chega muitas vezes
 * antes do aviso do Stripe: a pagina diz "a confirmar" e atualiza-se. O link
 * pode ser partilhado, por isso nunca mostra dados pessoais, e abri-lo nao
 * cria nem muda nada nem fala com ninguem.
 */
class PurchasePageTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
        $this->fakeStripe();
        app(SettingService::class)->set(StoreSettings::KEY_SHOP, 'on');
    }

    /** @param  array<string, mixed>  $overrides */
    private function checkout(array $overrides = []): Checkout
    {
        return Checkout::factory()->create($overrides);
    }

    private function open(Checkout $checkout): TestResponse
    {
        return $this->get('/compra/'.$checkout->token);
    }

    public function test_before_the_webhook_it_says_confirming(): void
    {
        $checkout = $this->checkout();

        $this->open($checkout)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('store/purchase')
            ->where('purchase.state', 'confirming')
            ->where('purchase.number', null)
            ->where('purchase.leadDays', null));
    }

    public function test_after_payment_it_shows_the_order(): void
    {
        $order = Order::factory()->paid()->create(['order_number' => '2026-0042']);
        $checkout = $this->checkout(['order_id' => $order->id, 'status' => 'completed']);

        $this->open($checkout)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('purchase.state', 'paid')
            ->where('purchase.number', '2026-0042')
            ->where('purchase.lines', [[
                'name' => 'Vaso',
                'label' => null,
                'qty' => 1,
                'lineCents' => 1500,
            ]])
            ->where('purchase.subtotalCents', 1500)
            ->where('purchase.shippingCents', 450)
            ->where('purchase.discountCents', 0)
            ->where('purchase.totalCents', 1950)
            ->where('purchase.leadDays', [1, 2]));
    }

    public function test_a_partially_refunded_order_still_counts_as_paid(): void
    {
        $order = Order::factory()->paid()->create(['payment_status' => 'partially_refunded']);

        $this->open($this->checkout(['order_id' => $order->id, 'status' => 'completed']))
            ->assertInertia(fn (Assert $page) => $page->where('purchase.state', 'paid'));
    }

    public function test_multibanco_says_awaiting_payment(): void
    {
        $order = Order::factory()->create(['payment_status' => 'pending', 'status' => 'pending_payment']);
        $checkout = $this->checkout(['order_id' => $order->id, 'status' => 'completed']);

        $this->open($checkout)->assertInertia(fn (Assert $page) => $page
            ->where('purchase.state', 'awaiting_payment')
            ->where('purchase.number', $order->order_number)
            ->where('purchase.leadDays', [1, 2]));
    }

    public function test_a_cancelled_or_refunded_order_is_closed(): void
    {
        foreach ([
            ['status' => 'cancelled', 'payment_status' => 'pending'],
            ['status' => 'refunded', 'payment_status' => 'refunded'],
            ['status' => 'paid', 'payment_status' => 'refunded'],
        ] as $states) {
            $order = Order::factory()->create($states);

            $this->open($this->checkout(['order_id' => $order->id, 'status' => 'completed']))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('purchase.state', 'closed')
                    ->where('purchase.leadDays', null));
        }
    }

    public function test_a_session_long_expired_without_an_order_says_expired(): void
    {
        $late = $this->checkout(['expires_at' => now()->subMinutes(11)]);
        $slack = $this->checkout(['expires_at' => now()->subMinutes(5)]);
        $closed = $this->checkout(['status' => 'expired', 'expires_at' => now()->addHour()]);

        $this->open($late)->assertInertia(fn (Assert $page) => $page->where('purchase.state', 'expired'));
        $this->open($slack)->assertInertia(fn (Assert $page) => $page->where('purchase.state', 'confirming'));
        $this->open($closed)->assertInertia(fn (Assert $page) => $page->where('purchase.state', 'expired'));
    }

    public function test_the_lead_time_is_the_longest_among_the_lines(): void
    {
        $product = Product::factory()->madeToOrder()->create(['production_time_days' => 5]);
        $variant = Variant::factory()->for($product)->create();
        $gone = Variant::factory()->for(Product::factory()->madeToOrder()->create(['production_time_days' => null]))->create();

        $order = Order::factory()->paid()->create();
        $line = fn (Variant $variant, string $mode): array => [
            'variant_id' => $variant->id,
            'product_name' => 'Peça',
            'variant_label' => null,
            'qty' => 1,
            'line_total_cents' => 1000,
            'fulfillment_mode' => $mode,
        ];
        $checkout = $this->checkout([
            'order_id' => $order->id,
            'status' => 'completed',
            'items_snapshot' => [$line($variant, 'made_to_order'), $line($gone, 'made_to_order'), $line($variant, 'in_stock')],
        ]);

        $this->open($checkout)->assertInertia(fn (Assert $page) => $page->where('purchase.leadDays', [5, 7]));
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->get('/compra/'.str_repeat('a', 64))->assertNotFound();
        $this->get('/compra/curto')->assertNotFound();
    }

    public function test_it_never_shows_personal_data(): void
    {
        $order = Order::factory()->paid()->create();
        $checkout = $this->checkout([
            'order_id' => $order->id,
            'status' => 'completed',
            'customer' => ['name' => 'Zacarias Pimentel', 'email' => 'zacarias@segredo.example', 'phone' => '961234567', 'nif' => '234567891'],
            'shipping_address' => [
                'name' => 'Zacarias Pimentel',
                'line1' => 'Travessa do Segredo 99',
                'line2' => null,
                'postalCode' => '4000-123',
                'city' => 'Vila Escondida',
                'country' => 'PT',
                'phone' => '961234567',
            ],
        ]);

        $body = $this->open($checkout)->assertOk()->getContent();

        foreach (['Zacarias', 'zacarias@segredo.example', '961234567', '234567891', 'Travessa do Segredo', '4000-123', 'Vila Escondida'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $body);
        }
    }

    public function test_it_is_not_indexed(): void
    {
        $response = $this->open($this->checkout())->assertOk()
            ->assertSee('<meta name="robots" content="noindex">', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
    }

    public function test_it_still_opens_with_the_shop_off(): void
    {
        $order = Order::factory()->paid()->create();
        $checkout = $this->checkout(['order_id' => $order->id, 'status' => 'completed']);
        app(SettingService::class)->set(StoreSettings::KEY_SHOP, 'off');

        $this->open($checkout)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('purchase.state', 'paid'));
    }

    public function test_it_opens_in_another_language(): void
    {
        $this->open($this->checkout())->assertOk();
        $this->get('/en/compra/'.$this->checkout()->token)->assertOk();
    }

    public function test_opening_it_calls_no_one(): void
    {
        $checkout = $this->checkout();
        $updatedAt = $checkout->updated_at;
        $this->stripeCalls = [];

        $this->travel(5)->minutes();
        $this->open($checkout)->assertOk();

        $this->assertSame([], $this->stripeCalls);
        $this->assertTrue($checkout->fresh()->updated_at->equalTo($updatedAt));
        $this->assertSame(1, Checkout::query()->count());
    }

    private function paidCheckout(string $email = 'ana@example.com'): Checkout
    {
        $order = Order::factory()->paid()->create(['email' => $email]);

        return $this->checkout(['order_id' => $order->id, 'status' => 'completed']);
    }

    public function test_a_paid_purchase_offers_to_save_the_account(): void
    {
        $this->open($this->paidCheckout())->assertInertia(fn (Assert $page) => $page
            ->where('purchase.canSaveAccount', true));
    }

    public function test_saving_sends_the_link_to_the_order_email(): void
    {
        Mail::fake();
        $checkout = $this->paidCheckout('Ana@Example.com');

        $this->post('/compra/'.$checkout->token.'/conta')
            ->assertRedirect('/compra/'.$checkout->token)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        Mail::assertQueued(CustomerLoginLinkMail::class, fn ($mail) => $mail->hasTo('ana@example.com'));
    }

    public function test_saving_answers_the_same_for_staff_and_sends_nothing(): void
    {
        Mail::fake();
        User::factory()->admin()->create(['email' => 'chefe@example.com']);
        $checkout = $this->paidCheckout('chefe@example.com');

        $this->post('/compra/'.$checkout->token.'/conta')
            ->assertRedirect('/compra/'.$checkout->token);

        Mail::assertNothingQueued();
    }

    public function test_no_offer_to_staff_signed_in_or_while_confirming(): void
    {
        $this->open($this->checkout())->assertInertia(fn (Assert $page) => $page
            ->where('purchase.canSaveAccount', false));

        User::factory()->admin()->create(['email' => 'chefe@example.com']);
        $this->open($this->paidCheckout('Chefe@example.com'))->assertInertia(fn (Assert $page) => $page
            ->where('purchase.canSaveAccount', false));

        $customer = User::factory()->create();
        $this->actingAs($customer, 'customer')
            ->get('/compra/'.$this->paidCheckout()->token)
            ->assertInertia(fn (Assert $page) => $page->where('purchase.canSaveAccount', false));
    }

    public function test_saving_sends_nothing_for_a_closed_order(): void
    {
        Mail::fake();
        $order = Order::factory()->create(['email' => 'ana@example.com', 'status' => 'cancelled']);
        $checkout = $this->checkout(['order_id' => $order->id, 'status' => 'completed']);

        $this->post('/compra/'.$checkout->token.'/conta')
            ->assertRedirect('/compra/'.$checkout->token);

        Mail::assertNothingQueued();
    }

    public function test_saving_sends_nothing_when_a_customer_is_signed_in(): void
    {
        Mail::fake();
        $checkout = $this->paidCheckout();

        $this->actingAs(User::factory()->create(), 'customer')
            ->post('/compra/'.$checkout->token.'/conta')
            ->assertRedirect('/compra/'.$checkout->token);

        Mail::assertNothingQueued();
    }

    public function test_saving_needs_an_order(): void
    {
        $this->post('/compra/'.$this->checkout()->token.'/conta')->assertNotFound();
    }
}
