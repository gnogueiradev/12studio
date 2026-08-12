<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_index_lists_orders_newest_first(): void
    {
        Order::factory()->create(['customer_name' => 'Antiga', 'created_at' => now()->subDay()]);
        Order::factory()->create(['customer_name' => 'Recente']);

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/encomendas/index')
                ->has('orders.data', 2)
                ->where('orders.data.0.customerName', 'Recente'));
    }

    public function test_filters_narrow_the_list(): void
    {
        Order::factory()->channel('vinted')->create();
        Order::factory()->channel('instagram')->create();

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.index', ['sales_channel' => 'vinted']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.salesChannel', 'vinted')
                ->where('filters.sales_channel', 'vinted'));
    }

    public function test_search_matches_the_order_number(): void
    {
        $order = Order::factory()->create();
        Order::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.index', ['search' => $order->order_number]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.orderNumber', $order->order_number));
    }

    public function test_the_list_is_paginated(): void
    {
        Order::factory()->count(25)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 20)
                ->where('orders.total', 25)
                ->where('orders.last_page', 2));
    }

    public function test_status_counts_ignore_the_status_filter(): void
    {
        Order::factory()->count(2)->create(['status' => 'pending_payment']);
        Order::factory()->count(3)->create(['status' => 'in_production']);

        // A lista obedece ao filtro, as contagens das chips nao: se
        // obedecessem, todas as chips excepto a activa mostravam zero e
        // deixavam de servir para navegar.
        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.index', ['status' => 'in_production']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 3)
                ->where('statusCounts.in_production', 3)
                ->where('statusCounts.pending_payment', 2));
    }

    public function test_status_counts_respect_the_other_filters(): void
    {
        Order::factory()->channel('vinted')->create(['status' => 'in_production']);
        Order::factory()->channel('instagram')->count(2)->create(['status' => 'in_production']);

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.index', ['sales_channel' => 'vinted']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('statusCounts.in_production', 1));
    }

    public function test_rows_carry_the_item_summary(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create(['product_name' => 'Vaso ondulado', 'qty' => 2]);
        OrderItem::factory()->for($order)->create(['product_name' => 'Suporte telemovel', 'qty' => 1]);

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.data.0.itemsSummary', 'Vaso ondulado')
                ->where('orders.data.0.itemsQty', 3)
                ->where('orders.data.0.itemsCount', 2));
    }

    public function test_rows_carry_a_short_date_in_portuguese(): void
    {
        Order::factory()->create(['created_at' => '2026-08-09 14:30:00']);

        // Formatada no servidor com um mapa PT explicito, nao com o locale:
        // o APP_LOCALE tem 'en' por omissao e a data da listagem nao pode
        // mudar de idioma consoante o .env.
        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.data.0.createdAtShort', '09 ago')
                ->where('orders.data.0.createdAt', '2026-08-09 14:30'));
    }

    public function test_non_admins_cannot_list_orders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.encomendas.index'))
            ->assertForbidden();
    }
}
