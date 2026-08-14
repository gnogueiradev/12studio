<?php

namespace Tests\Feature\Admin;

use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
            'customer_type' => 'particular',
            'phone' => '912345678',
            'nif' => '123456789',
            'admin_note' => 'Prefere entrega em mão.',
            'line1' => 'Rua das Flores 12',
            'line2' => '2.º Esq.',
            'postal_code' => '4000-123',
            'city' => 'Porto',
            'country' => 'PT',
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
        // Telefone e NIF sao da pessoa, nao da morada de envio.
        $this->assertSame('912345678', $customer->phone);
        $this->assertSame('123456789', $customer->nif);
        $this->assertSame('particular', $customer->customer_type);
        $this->assertDatabaseHas('addresses', [
            'user_id' => $customer->id,
            'postal_code' => '4000-123',
            'is_default' => true,
        ]);
    }

    public function test_a_customer_can_be_created_with_nothing_but_a_name(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.clientes.store'), [
                'name' => 'Cliente de balcão',
                'customer_type' => 'particular',
            ])
            ->assertSessionHasNoErrors();

        $customer = User::query()->where('name', 'Cliente de balcão')->firstOrFail();

        $this->assertNull($customer->email);
        $this->assertSame(0, $customer->addresses()->count());
    }

    public function test_two_customers_can_coexist_without_email(): void
    {
        $payload = ['customer_type' => 'particular'];

        $this->actingAs($this->admin)
            ->post(route('admin.clientes.store'), [...$payload, 'name' => 'Primeiro'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('admin.clientes.store'), [...$payload, 'name' => 'Segundo'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, User::query()->whereNull('email')->count());
    }

    /**
     * O `change()` que tornou o email nullable recria a tabela inteira em
     * SQLite. Se o indice unico nao sobrevivesse a essa recriacao, dois
     * utilizadores podiam ficar com o mesmo email — e o login da Fase 5 deixava
     * de ter chave.
     */
    public function test_the_email_index_survived_the_migration(): void
    {
        $unique = collect(Schema::getIndexes('users'))
            ->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['email']);

        $this->assertTrue($unique, 'users.email perdeu o indice unico.');
    }

    public function test_a_company_needs_a_nif(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.clientes.store'), [
                'name' => 'Café Bonjardim',
                'customer_type' => 'empresa',
            ])
            ->assertSessionHasErrors('nif');
    }

    public function test_half_an_address_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.clientes.store'), [
                'name' => 'Meia morada',
                'customer_type' => 'particular',
                'city' => 'Porto',
            ])
            ->assertSessionHasErrors(['line1', 'postal_code']);
    }

    public function test_clearing_the_address_removes_it(): void
    {
        $customer = User::factory()->create();
        Address::factory()->create(['user_id' => $customer->id]);

        $this->actingAs($this->admin)
            ->patch(route('admin.clientes.update', $customer), [
                'name' => $customer->name,
                'email' => $customer->email,
                'customer_type' => 'particular',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $customer->addresses()->count());
    }

    public function test_the_modal_can_ask_to_stay_on_the_list_or_open_an_order(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.clientes.store'), [...$this->validPayload(), 'after' => 'list'])
            ->assertRedirect(route('admin.clientes.index'));

        $this->actingAs($this->admin)
            ->post(route('admin.clientes.store'), [
                'name' => 'Outro cliente',
                'customer_type' => 'particular',
                'after' => 'order',
            ])
            ->assertRedirect(route('admin.encomendas.create'));
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

    public function test_the_edit_modal_lists_the_customer_orders(): void
    {
        $customer = User::factory()->create();
        Address::factory()->create(['user_id' => $customer->id]);
        Order::factory()->count(2)->create(['user_id' => $customer->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index', ['editar' => $customer->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/clientes/index')
                ->where('editing.customer.id', $customer->id)
                ->has('editing.orders', 2)
                ->where('editing.customer.canDelete', false));
    }

    public function test_the_listing_carries_no_customer_without_the_parameter(): void
    {
        User::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('editing', null));
    }

    /**
     * O parametro vem do URL, e um URL escreve-se a mao. Nenhum destes casos
     * pode rebentar a listagem: o modal simplesmente nao abre.
     */
    public function test_an_unusable_editar_parameter_opens_no_modal(): void
    {
        foreach (['abc', '0', '-1', '99999', ''] as $value) {
            $this->actingAs($this->admin)
                ->get(route('admin.clientes.index', ['editar' => $value]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('editing', null));
        }
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

        // O modal nao abre num administrador: a listagem responde, mas sem
        // `editing`. O `update` continua a dar 404, que e onde isto importa.
        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index', ['editar' => $otherAdmin->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('editing', null));

        $this->actingAs($this->admin)
            ->patch(route('admin.clientes.update', $otherAdmin), $this->validPayload())
            ->assertNotFound();
    }

    public function test_index_searches_by_name_email_and_nif(): void
    {
        User::factory()->create(['name' => 'Júlia Marques', 'nif' => '999888777']);
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
