<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_filters_narrow_the_list(): void
    {
        User::factory()->create(['name' => 'Ana Marques']);
        User::factory()->company()->create(['name' => 'Café Bonjardim']);

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index', ['customer_type' => 'empresa']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/clientes/index')
                ->has('customers.data', 1)
                ->where('customers.data.0.name', 'Café Bonjardim')
                ->where('customers.data.0.customerType', 'empresa'));
    }

    public function test_the_channel_filter_matches_any_order_in_that_channel(): void
    {
        $vinted = User::factory()->create(['name' => 'Rui Tavares']);
        Order::factory()->channel('vinted')->create(['user_id' => $vinted->id]);

        $website = User::factory()->create(['name' => 'Marta Nunes']);
        Order::factory()->channel('website')->create(['user_id' => $website->id]);

        // Sem encomendas nenhumas: nao aparece em nenhum canal.
        User::factory()->create(['name' => 'Sem compras']);

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index', ['sales_channel' => 'vinted']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.name', 'Rui Tavares'));
    }

    public function test_type_counts_ignore_the_type_filter(): void
    {
        User::factory()->count(3)->create();
        User::factory()->company()->count(2)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index', ['customer_type' => 'empresa']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // A tabela obedece ao filtro...
                ->has('customers.data', 2)
                // ...as chips continuam a mostrar o total de cada tipo, senao
                // nao havia como voltar aos particulares.
                ->where('typeCounts.particular', 3)
                ->where('typeCounts.empresa', 2));
    }

    public function test_the_type_counts_respect_the_other_filters(): void
    {
        User::factory()->create(['name' => 'Ana Marques']);
        User::factory()->company()->create(['name' => 'Café Bonjardim']);

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index', ['search' => 'Ana']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('typeCounts.particular', 1)
                ->missing('typeCounts.empresa'));
    }

    public function test_the_habitual_channel_is_the_most_frequent_not_the_most_recent(): void
    {
        $customer = User::factory()->create();

        Order::factory()->count(3)->channel('website')->create([
            'user_id' => $customer->id,
            'created_at' => now()->subWeek(),
        ]);
        // Uma venda avulsa, mais recente, nao pode mudar o habito.
        Order::factory()->channel('instagram')->create([
            'user_id' => $customer->id,
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.data.0.habitualChannel', 'website')
                ->where('customers.data.0.ordersCount', 4));
    }

    public function test_a_customer_without_orders_has_no_habitual_channel(): void
    {
        User::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.data.0.habitualChannel', null)
                ->where('customers.data.0.lastOrderAtShort', null));
    }

    public function test_the_total_spent_counts_only_paid_orders(): void
    {
        $customer = User::factory()->create();

        Order::factory()->paid()->create(['user_id' => $customer->id, 'total_cents' => 5000]);
        Order::factory()->create(['user_id' => $customer->id, 'total_cents' => 9900]);

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.data.0.paidTotalCents', 5000));
    }

    public function test_the_stat_cards_summarise_the_whole_customer_base(): void
    {
        // Recorrente (2 encomendas pagas, 200,00 EUR no total) e novo este mes.
        $recurring = User::factory()->create();
        Order::factory()->paid()->count(2)->create([
            'user_id' => $recurring->id,
            'total_cents' => 10000,
        ]);

        // Uma so encomenda paga, de 40,00 EUR.
        $single = User::factory()->create();
        Order::factory()->paid()->create(['user_id' => $single->id, 'total_cents' => 4000]);

        // Sem nada pago: conta para o total, fica fora da media.
        User::factory()->create(['created_at' => now()->subMonths(3)]);

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 3)
                ->where('stats.recurring', 1)
                ->where('stats.newThisMonth', 2)
                // (20000 + 4000) / 2 — o cliente sem compras nao entra.
                ->where('stats.averagePaidCents', 12000));
    }

    public function test_the_stat_cards_ignore_the_filters(): void
    {
        User::factory()->count(2)->create();
        User::factory()->company()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index', ['customer_type' => 'empresa']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('stats.total', 3));
    }

    public function test_the_list_is_paginated(): void
    {
        User::factory()->count(25)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 20)
                ->where('customers.total', 25));
    }

    public function test_admins_never_show_up_in_the_customer_list(): void
    {
        User::factory()->admin()->create();
        User::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('stats.total', 1));
    }
}
