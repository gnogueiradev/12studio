<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemStatusHistory;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductionBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_board_shows_only_items_that_need_printing(): void
    {
        $order = Order::factory()->paid()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);
        OrderItem::factory()->madeToOrder()->create([
            'order_id' => $order->id,
            'product_name' => 'Vaso Espiral',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.producao'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/producao/index')
                ->has('items', 1)
                ->where('items.0.productName', 'Vaso Espiral'));
    }

    public function test_cancelled_orders_leave_the_board(): void
    {
        $order = Order::factory()->create(['status' => 'cancelled']);
        OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.producao'))
            ->assertInertia(fn (Assert $page) => $page->has('items', 0));
    }

    public function test_personalization_reaches_the_card_with_labels(): void
    {
        $order = Order::factory()->paid()->create();
        OrderItem::factory()->madeToOrder()->create([
            'order_id' => $order->id,
            'personalization' => ['nome_gravado' => 'Júlia'],
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.producao'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.0.personalization.0.label', 'Nome gravado')
                ->where('items.0.personalization.0.value', 'Júlia'));
    }

    public function test_advancing_a_card_records_the_item_history(): void
    {
        $order = Order::factory()->paid()->create(['status' => 'in_production']);
        $item = OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id]);

        $this->actingAs($this->admin)
            ->patch(route('admin.itens.producao', $item), [
                'production_status' => 'printing',
            ])
            ->assertRedirect();

        $this->assertSame('printing', $item->refresh()->production_status);
        $this->assertDatabaseHas('order_item_status_histories', [
            'order_item_id' => $item->id,
            'from_status' => 'awaiting_production',
            'to_status' => 'printing',
            'changed_by_user_id' => $this->admin->id,
        ]);
    }

    public function test_the_last_ready_item_pushes_the_order_to_ready_to_ship(): void
    {
        $order = Order::factory()->paid()->create(['status' => 'in_production']);
        $item = OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id]);

        $this->actingAs($this->admin)
            ->patch(route('admin.itens.producao', $item), ['production_status' => 'ready']);

        $this->assertSame('ready_to_ship', $order->refresh()->status);
    }

    public function test_a_card_can_move_back_within_the_pipeline(): void
    {
        $order = Order::factory()->paid()->create(['status' => 'in_production']);
        $item = OrderItem::factory()->madeToOrder()->create([
            'order_id' => $order->id,
            'production_status' => 'quality_check',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.itens.producao', $item), [
                'production_status' => 'printing',
            ])
            ->assertRedirect();

        $this->assertSame('printing', $item->refresh()->production_status);
        $this->assertDatabaseHas('order_item_status_histories', [
            'order_item_id' => $item->id,
            'from_status' => 'quality_check',
            'to_status' => 'printing',
        ]);
    }

    public function test_moving_back_from_ready_reopens_the_order(): void
    {
        $order = Order::factory()->paid()->create(['status' => 'in_production']);
        $item = OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id]);

        $this->actingAs($this->admin)
            ->patch(route('admin.itens.producao', $item), ['production_status' => 'ready']);

        $this->assertSame('ready_to_ship', $order->refresh()->status);

        $this->actingAs($this->admin)
            ->patch(route('admin.itens.producao', $item), ['production_status' => 'quality_check']);

        $this->assertSame('in_production', $order->refresh()->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => 'ready_to_ship',
            'to_status' => 'in_production',
        ]);
    }

    public function test_a_shipped_order_refuses_a_move_back(): void
    {
        $order = Order::factory()->paid()->create(['status' => 'shipped']);
        $item = OrderItem::factory()->madeToOrder()->create([
            'order_id' => $order->id,
            'production_status' => 'ready',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.itens.producao', $item), [
                'production_status' => 'printing',
            ])
            ->assertRedirect();

        $this->assertSame('ready', $item->refresh()->production_status);
        $this->assertSame('shipped', $order->refresh()->status);
    }

    public function test_the_card_carries_the_estimated_time_and_position(): void
    {
        $order = Order::factory()->paid()->create();
        $variant = Variant::factory()->create(['printing_time_minutes' => 90]);

        OrderItem::factory()->madeToOrder()->create([
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'qty' => 4,
        ]);
        OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.producao'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 2)
                // 90 minutos por peca x 4 pecas.
                ->where('items.0.estimatedMinutes', 360)
                ->where('items.0.positionInOrder', 1)
                ->where('items.0.totalInOrder', 2)
                ->where('items.0.orderReadyToShip', false)
                // Sem variante nao ha estimativa — nao se inventa um numero.
                ->where('items.1.estimatedMinutes', null)
                ->where('items.1.positionInOrder', 2));
    }

    public function test_the_printing_start_time_reaches_the_card(): void
    {
        // Relogio parado: o cartao mostra a hora gravada no PATCH, e sem isto a
        // assercao lia o relogio outra vez — uma viragem de minuto entre as duas
        // leituras chumbava o teste.
        $this->freezeTime();

        $order = Order::factory()->paid()->create(['status' => 'in_production']);
        $item = OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id]);

        $this->actingAs($this->admin)
            ->patch(route('admin.itens.producao', $item), ['production_status' => 'printing']);

        $startedAt = now()->format('H:i');

        $this->actingAs($this->admin)
            ->get(route('admin.producao'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.0.startedPrintingAt', $startedAt));
    }

    /**
     * Item pronto ha `$hours` horas, numa encomenda com o estado dado.
     */
    private function readyItem(string $orderStatus, int $hours, string $name = 'Vaso Pronto'): OrderItem
    {
        $order = Order::factory()->paid()->create(['status' => $orderStatus]);
        $item = OrderItem::factory()->madeToOrder()->create([
            'order_id' => $order->id,
            'product_name' => $name,
            'production_status' => 'ready',
        ]);

        OrderItemStatusHistory::query()->forceCreate([
            'order_item_id' => $item->id,
            'from_status' => 'quality_check',
            'to_status' => 'ready',
            'created_at' => now()->subHours($hours),
        ]);

        return $item;
    }

    public function test_a_ready_item_stays_on_the_board_for_a_day(): void
    {
        $this->readyItem('ready_to_ship', hours: 23, name: 'Ontem');
        $this->readyItem('ready_to_ship', hours: 25, name: 'Anteontem');

        $this->actingAs($this->admin)
            ->get(route('admin.producao'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 1)
                ->where('items.0.productName', 'Ontem')
                ->where('readyVisibleHours', 24));
    }

    public function test_a_ready_item_leaves_as_soon_as_the_order_ships(): void
    {
        $this->readyItem('shipped', hours: 1);
        $this->readyItem('delivered', hours: 1);

        $this->actingAs($this->admin)
            ->get(route('admin.producao'))
            ->assertInertia(fn (Assert $page) => $page->has('items', 0));
    }

    public function test_the_time_limit_only_applies_to_ready_items(): void
    {
        $order = Order::factory()->paid()->create(['status' => 'in_production', 'created_at' => now()->subWeeks(2)]);
        OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id, 'created_at' => now()->subWeeks(2)]);

        $this->actingAs($this->admin)
            ->get(route('admin.producao'))
            ->assertInertia(fn (Assert $page) => $page->has('items', 1));
    }

    public function test_a_ready_item_without_history_uses_its_last_update(): void
    {
        $order = Order::factory()->paid()->create(['status' => 'ready_to_ship']);
        OrderItem::factory()->madeToOrder()->create([
            'order_id' => $order->id,
            'production_status' => 'ready',
            'updated_at' => now()->subDays(3),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.producao'))
            ->assertInertia(fn (Assert $page) => $page->has('items', 0));
    }

    public function test_a_hidden_sibling_still_counts_for_the_card_context(): void
    {
        $old = $this->readyItem('in_production', hours: 30, name: 'Pronto ha muito');
        $printing = OrderItem::factory()->madeToOrder()->create([
            'order_id' => $old->order_id,
            'product_name' => 'A imprimir',
            'production_status' => 'printing',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.producao'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 1)
                ->where('items.0.id', $printing->id)
                ->where('items.0.positionInOrder', 2)
                ->where('items.0.totalInOrder', 2)
                ->where('items.0.orderReadyToShip', false));
    }

    public function test_non_admins_cannot_reach_the_board(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.producao'))
            ->assertForbidden();
    }
}
