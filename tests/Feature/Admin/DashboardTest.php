<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_dashboard_ships_every_block(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/dashboard')
                ->has('today')
                ->has('storeOpen')
                ->has('kpis')
                ->has('statusCounts', 5)
                ->has('weeklySales', 12)
                ->has('recentOrders')
                ->has('production')
                ->has('alerts'));
    }

    /**
     * O KPI de receita e o eixo FINANCEIRO da encomenda: sai de
     * `payment_status`, nao de `status`. Sem isto uma encomenda enviada mas
     * nunca paga entrava na receita.
     */
    public function test_revenue_counts_only_paid_orders_inside_the_window(): void
    {
        Order::factory()->paid()->create(['total_cents' => 5000]);
        Order::factory()->paid()->create(['total_cents' => 3000]);

        // Paga, mas fora da janela de 30 dias.
        Order::factory()->paid()->create([
            'total_cents' => 9900,
            'paid_at' => now()->subDays(40),
        ]);

        // Ja enviada e por pagar: conta para o pipeline, nao para a receita.
        Order::factory()->create([
            'status' => 'shipped',
            'payment_status' => 'pending',
            'total_cents' => 7700,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.revenue30Cents', 8000)
                ->where('kpis.paidOrders30', 2)
                ->where('kpis.avgOrderCents', 4000));
    }

    public function test_the_revenue_delta_is_null_without_a_previous_window(): void
    {
        Order::factory()->paid()->create(['total_cents' => 5000]);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.revenueDeltaPercent', null));
    }

    public function test_the_revenue_delta_compares_the_two_windows(): void
    {
        Order::factory()->paid()->create(['total_cents' => 12000]);
        Order::factory()->paid()->create([
            'total_cents' => 10000,
            'paid_at' => now()->subDays(45),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.revenueDeltaPercent', 20));
    }

    /**
     * Um estado sem encomendas tem de vir a zero e nao desaparecer: as barras
     * saltavam de sitio entre visitas.
     */
    public function test_status_counts_cover_the_whole_open_pipeline(): void
    {
        Order::factory()->count(2)->create(['status' => 'pending_payment']);
        Order::factory()->create(['status' => 'in_production']);
        // Terminais: ficam de fora da distribuicao.
        Order::factory()->create(['status' => 'delivered']);
        Order::factory()->create(['status' => 'cancelled']);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('statusCounts', 5)
                ->where('statusCounts.0.status', 'pending_payment')
                ->where('statusCounts.0.count', 2)
                ->where('statusCounts.2.status', 'in_production')
                ->where('statusCounts.2.count', 1)
                ->where('statusCounts.4.status', 'shipped')
                ->where('statusCounts.4.count', 0));
    }

    public function test_low_stock_counts_available_stock_against_the_threshold(): void
    {
        $product = Product::factory()->create(['name' => 'Vaso ondulado']);

        // 10 - 8 = 2 disponiveis, limite 3 → em falta.
        Variant::factory()->for($product)->create([
            'stock' => 10,
            'reserved_stock' => 8,
            'low_stock_threshold' => 3,
        ]);

        // 10 disponiveis, limite 3 → folgado.
        Variant::factory()->create([
            'stock' => 10,
            'reserved_stock' => 0,
            'low_stock_threshold' => 3,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.lowStockCount', 1)
                ->where('kpis.lowStockNames', ['Vaso ondulado']));
    }

    public function test_low_stock_ignores_variants_that_are_not_on_sale(): void
    {
        Variant::factory()->create([
            'active' => false,
            'stock' => 0,
            'low_stock_threshold' => 3,
        ]);

        Variant::factory()
            ->for(Product::factory()->create(['status' => 'draft']))
            ->create(['stock' => 0, 'low_stock_threshold' => 3]);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('kpis.lowStockCount', 0));
    }

    public function test_the_alert_strip_stays_empty_on_a_clean_shop(): void
    {
        Order::factory()->paid()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->has('alerts', 0));
    }

    public function test_an_order_stuck_in_production_raises_an_alert(): void
    {
        $order = Order::factory()->create([
            'status' => 'in_production',
            'created_at' => now()->subDays(5),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('alerts', 1)
                ->where('alerts.0.label', "#{$order->order_number} está há 5 dias em produção")
                ->where('alerts.0.href', "/admin/encomendas/{$order->id}"));
    }

    public function test_a_stock_issue_raises_an_alert(): void
    {
        Order::factory()->create(['stock_issue' => true]);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('alerts', 1)
                ->where('alerts.0.label', '1 encomenda com problema de stock'));
    }

    public function test_the_production_block_shows_what_is_printing(): void
    {
        $order = Order::factory()->create(['status' => 'in_production']);

        OrderItem::factory()->for($order)->create([
            'product_name' => 'Candeeiro espiral',
            'production_status' => 'printing',
        ]);
        OrderItem::factory()->for($order)->create(['production_status' => 'awaiting_production']);

        // Encomenda morta: nem o item a imprimir nem o da fila contam.
        $cancelled = Order::factory()->create(['status' => 'cancelled']);
        OrderItem::factory()->for($cancelled)->create(['production_status' => 'printing']);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('production.printing', 1)
                ->where('production.printing.0.productName', 'Candeeiro espiral')
                ->where('production.printing.0.orderNumber', $order->order_number)
                ->where('production.queued', 1));
    }

    /**
     * A encomenda mais recente e a primeira linha, e o resumo poupa um clique
     * para perceber do que se trata.
     */
    public function test_recent_orders_are_newest_first_with_an_item_summary(): void
    {
        $older = Order::factory()->create(['created_at' => now()->subDay()]);
        OrderItem::factory()->for($older)->create();

        $newest = Order::factory()->create();
        OrderItem::factory()->for($newest)->create([
            'product_name' => 'Vaso ondulado',
            'qty' => 2,
        ]);
        OrderItem::factory()->for($newest)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('recentOrders', 2)
                ->where('recentOrders.0.orderNumber', $newest->order_number)
                ->where('recentOrders.0.summary', 'Vaso ondulado · 2 un. +1'));
    }

    public function test_non_admins_cannot_open_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }
}
