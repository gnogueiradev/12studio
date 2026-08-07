<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
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

    public function test_non_admins_cannot_reach_the_board(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.producao'))
            ->assertForbidden();
    }
}
