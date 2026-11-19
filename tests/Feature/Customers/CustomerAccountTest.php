<?php

namespace Tests\Feature\Customers;

use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use App\Services\SettingService;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Stripe\FakesStripe;
use Tests\TestCase;

/**
 * A conta do cliente em /conta: so com sessao de cliente (a guarda `customer`),
 * so as encomendas ligadas a ela, e os dados e as ofertas mudam-se aqui sem
 * nunca tocar no email. Uma conta desativada, ou promovida a equipa, deixa de
 * entrar na pagina seguinte, e sair funciona mesmo com as compras desligadas.
 */
class CustomerAccountTest extends TestCase
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

    private function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $this->actingAs($user, 'customer');
        // Como no browser: a guarda por omissao continua a `web`.
        Auth::shouldUse('web');

        return $user;
    }

    public function test_the_account_needs_a_customer_session(): void
    {
        $this->get('/conta')->assertRedirect('/entrar');
        $this->get('/en/conta')->assertRedirect('/en/entrar');

        // Uma sessao da equipa (guarda `web`) nao e uma sessao de cliente.
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->get('/conta')->assertRedirect('/entrar');
    }

    public function test_staff_previewing_the_closed_store_reach_the_account(): void
    {
        config(['access.store_open' => false]);
        app(SettingService::class)->set(StoreSettings::KEY_SHOP, 'staff');

        // As duas sessoes no mesmo browser; a do cliente fica por omissao,
        // como depois do `auth:customer`.
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->actingAs(User::factory()->create(), 'customer');

        $this->get('/conta')->assertOk();
    }

    public function test_the_account_lists_only_linked_orders(): void
    {
        $user = $this->signIn();
        $mine = Order::factory()->paid()->create(['user_id' => $user->id, 'order_number' => '2026-0001']);
        Order::factory()->paid()->create(['user_id' => User::factory()->create()->id, 'order_number' => '2026-0002']);
        Order::factory()->paid()->create(['user_id' => null, 'email' => $user->email, 'order_number' => '2026-0003']);

        $response = $this->get('/conta');

        $response->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/account/index')
                ->has('orders', 1)
                ->where('orders.0.number', $mine->order_number)
                ->where('account.email', $user->email)
                ->where('account.offers', true));
    }

    public function test_another_customers_order_is_404(): void
    {
        $user = $this->signIn();
        $mine = Order::factory()->paid()->create(['user_id' => $user->id, 'order_number' => '2026-0010']);
        $theirs = Order::factory()->paid()->create(['user_id' => User::factory()->create()->id, 'order_number' => '2026-0011']);

        $this->get('/conta/encomendas/'.$mine->order_number)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('store/account/order')->where('order.number', '2026-0010'));
        $this->get('/conta/encomendas/'.$theirs->order_number)->assertNotFound();
        $this->post('/conta/encomendas/'.$theirs->order_number.'/comprar-outra-vez')->assertNotFound();
    }

    public function test_details_update_the_default_address_and_never_the_email(): void
    {
        $user = $this->signIn(User::factory()->create(['email' => 'ana@exemplo.pt']));
        Address::factory()->create(['user_id' => $user->id, 'line1' => 'Rua Velha 1']);

        $this->from('/conta')->patch('/conta/dados', [
            'name' => 'Ana Nova',
            'email' => 'outra@exemplo.pt',
            'phone' => '912 345 678',
            'nif' => '999 999 990',
            'line1' => 'Rua Nova 2',
            'line2' => '3.º Esq.',
            'postal_code' => '1000-001',
            'city' => 'Lisboa',
        ])->assertRedirect('/conta');

        $user->refresh();
        $this->assertSame('ana@exemplo.pt', $user->email);
        $this->assertSame('Ana Nova', $user->name);
        $this->assertSame('912 345 678', $user->phone);
        $this->assertSame('999999990', $user->nif);

        $this->assertSame(1, $user->addresses()->count());
        $address = $user->addresses()->firstOrFail();
        $this->assertSame('Rua Nova 2', $address->line1);
        $this->assertSame('3.º Esq.', $address->line2);
        $this->assertSame('1000-001', $address->postal_code);
        $this->assertSame('Lisboa', $address->city);
        $this->assertSame('PT', $address->country);
        $this->assertSame('Ana Nova', $address->name);
    }

    public function test_details_are_validated(): void
    {
        $this->signIn();

        $this->from('/conta')->patch('/conta/dados', [
            'name' => '', 'phone' => 'abc', 'nif' => '123', 'line1' => '', 'postal_code' => '12', 'city' => '',
        ])->assertSessionHasErrors(['name', 'phone', 'nif', 'line1', 'postal_code', 'city']);
    }

    public function test_the_offers_switch_sets_and_clears_the_opt_out(): void
    {
        $user = $this->signIn();

        $this->from('/conta')->patch('/conta/ofertas', ['enabled' => false])->assertRedirect('/conta');
        $this->assertNotNull($user->fresh()->marketing_opt_out_at);

        $this->from('/conta')->patch('/conta/ofertas', ['enabled' => true])->assertRedirect('/conta');
        $this->assertNull($user->fresh()->marketing_opt_out_at);
    }

    public function test_a_disabled_customer_is_signed_out(): void
    {
        $user = $this->signIn();
        $this->get('/conta')->assertOk();

        $user->forceFill(['disabled_at' => now()])->save();

        $this->get('/conta')->assertRedirect('/entrar');
        $this->assertGuest('customer');
    }

    public function test_a_staff_user_is_signed_out_of_the_customer_area(): void
    {
        $user = $this->signIn();
        $this->get('/conta')->assertOk();

        $user->forceFill(['is_admin' => true])->save();

        $this->get('/conta')->assertRedirect('/entrar');
        $this->assertGuest('customer');
    }

    public function test_logout_works_with_the_shop_off(): void
    {
        $this->signIn();
        app(SettingService::class)->set(StoreSettings::KEY_SHOP, 'off');

        $this->post('/conta/sair')->assertRedirect('/');
        $this->assertGuest('customer');
    }
}
