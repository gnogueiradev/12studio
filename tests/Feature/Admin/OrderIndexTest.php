<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
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

    public function test_non_admins_cannot_list_orders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.encomendas.index'))
            ->assertForbidden();
    }
}
