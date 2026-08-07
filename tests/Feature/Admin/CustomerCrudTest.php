<?php

namespace Tests\Feature\Admin;

use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'Júlia Marques',
            'email' => 'julia@example.test',
            'line1' => 'Rua das Flores 12',
            'line2' => '2.º Esq.',
            'postal_code' => '4000-123',
            'city' => 'Porto',
            'country' => 'PT',
            'phone' => '912345678',
            'nif' => '123456789',
        ];
    }

    public function test_store_creates_a_user_and_an_address(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.clientes.store'), $this->validPayload())
            ->assertRedirect();

        $customer = User::query()->where('email', 'julia@example.test')->firstOrFail();

        $this->assertFalse($customer->isAdmin());
        $this->assertNotEmpty($customer->password);
        $this->assertDatabaseHas('addresses', [
            'user_id' => $customer->id,
            'postal_code' => '4000-123',
            'nif' => '123456789',
            'is_default' => true,
        ]);
    }

    public function test_a_customer_created_here_is_never_an_admin(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.clientes.store'), [
                ...$this->validPayload(),
                'is_admin' => true,
            ]);

        $this->assertFalse(
            User::query()->where('email', 'julia@example.test')->firstOrFail()->isAdmin()
        );
    }

    public function test_a_malformed_postal_code_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.clientes.store'), [
                ...$this->validPayload(),
                'postal_code' => '4000',
            ])
            ->assertSessionHasErrors('postal_code');
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'julia@example.test']);

        $this->actingAs($this->admin)
            ->post(route('admin.clientes.store'), $this->validPayload())
            ->assertSessionHasErrors('email');
    }

    public function test_update_replaces_the_single_address(): void
    {
        $customer = User::factory()->create();
        Address::factory()->create(['user_id' => $customer->id, 'city' => 'Lisboa']);

        $this->actingAs($this->admin)
            ->patch(route('admin.clientes.update', $customer), $this->validPayload());

        $this->assertSame(1, $customer->addresses()->count());
        $this->assertDatabaseHas('addresses', [
            'user_id' => $customer->id,
            'city' => 'Porto',
        ]);
    }

    public function test_the_detail_page_lists_the_customer_orders(): void
    {
        $customer = User::factory()->create();
        Address::factory()->create(['user_id' => $customer->id]);
        Order::factory()->count(2)->create(['user_id' => $customer->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.edit', $customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/clientes/edit')
                ->has('orders', 2)
                ->where('customer.canDelete', false));
    }

    public function test_a_customer_without_orders_can_be_deleted(): void
    {
        $customer = User::factory()->create();
        Address::factory()->create(['user_id' => $customer->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.clientes.destroy', $customer))
            ->assertRedirect(route('admin.clientes.index'));

        $this->assertDatabaseMissing('users', ['id' => $customer->id]);
        $this->assertDatabaseMissing('addresses', ['user_id' => $customer->id]);
    }

    public function test_a_customer_with_orders_survives_a_delete_attempt(): void
    {
        $customer = User::factory()->create();
        Order::factory()->create(['user_id' => $customer->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.clientes.destroy', $customer));

        $this->assertDatabaseHas('users', ['id' => $customer->id]);
    }

    public function test_admins_are_not_reachable_through_the_customer_routes(): void
    {
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.edit', $otherAdmin))
            ->assertNotFound();
    }

    public function test_index_searches_by_name_email_and_nif(): void
    {
        $match = User::factory()->create(['name' => 'Júlia Marques']);
        Address::factory()->create(['user_id' => $match->id, 'nif' => '999888777']);
        User::factory()->create(['name' => 'Outro Cliente']);

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index', ['search' => '999888777']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/clientes/index')
                ->has('customers.data', 1)
                ->where('customers.data.0.name', 'Júlia Marques'));
    }

    public function test_non_admins_cannot_touch_customers(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.clientes.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'julia@example.test']);
    }
}
