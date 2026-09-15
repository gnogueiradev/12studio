<?php

namespace Tests\Feature\Admin;

use App\Mail\OrderShippedMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderTransitionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_a_mixed_order_only_ships_when_the_printed_item_is_ready(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);
        $printed = OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id]);

        $orders = app(OrderService::class);
        $orders->setPaymentStatus($order, 'paid', $this->admin);

        $this->assertSame('in_production', $order->refresh()->status);

        $orders->setItemProductionStatus($printed, 'printing', $this->admin);
        $this->assertSame('in_production', $order->refresh()->status);

        $orders->setItemProductionStatus($printed->refresh(), 'quality_check', $this->admin);
        $this->assertSame('in_production', $order->refresh()->status);

        $orders->setItemProductionStatus($printed->refresh(), 'ready', $this->admin);
        $this->assertSame('ready_to_ship', $order->refresh()->status);
    }

    public function test_shipping_sends_the_email_and_stamps_the_date(): void
    {
        $order = Order::factory()->paid()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.estado', $order), [
                'status' => 'shipped',
                'note' => 'Entregue nos CTT.',
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('shipped', $order->status);
        $this->assertNotNull($order->shipped_at);

        Mail::assertQueued(OrderShippedMail::class);
    }

    public function test_cancelling_returns_stock_to_the_shelf(): void
    {
        $variant = Variant::factory()->stock(3)->create([
            'product_id' => Product::factory()->create()->id,
        ]);
        $order = Order::factory()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'qty' => 2,
            'fulfillment_mode' => 'in_stock',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.estado', $order), [
                'status' => 'cancelled',
                'note' => 'Cliente desistiu.',
            ]);

        $this->assertSame(5, $variant->refresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $variant->id,
            'delta' => 2,
            'reason' => 'restock_cancel',
        ]);
    }

    public function test_advancing_an_unpaid_order_without_force_is_rejected(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.estado', $order), [
                'status' => 'shipped',
            ])
            ->assertRedirect();

        $this->assertSame('pending_payment', $order->refresh()->status);
    }

    /**
     * O bug do "Avançar estado": depois de um primeiro avanço, o formulário
     * ficava com o estado escolhido antes — que já era o atual — e voltar a
     * carregar em Aplicar pedia o estado em que a encomenda já estava. O
     * service trata isso como no-op, e o controller respondia "Estado
     * atualizado." sem nada ter mudado.
     */
    public function test_asking_for_the_status_the_order_already_has_is_not_reported_as_a_change(): void
    {
        $order = Order::factory()->paid()->create();
        $histories = $order->statusHistories()->count();

        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.estado', $order), ['status' => 'paid'])
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');

        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame($histories, $order->statusHistories()->count());
    }

    /**
     * "Pagamento confirmado" e o degrau que o pagamento pisa — nao um sitio
     * para onde se empurra a encomenda a mao. Forcado com o pagamento
     * pendente, deixava a encomenda num estado que o advanceAfterPayment ja
     * nao reconhecia: quando o dinheiro chegava, nunca ia para producao.
     */
    public function test_forcing_an_unpaid_order_into_payment_confirmed_is_rejected(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id]);

        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.estado', $order), [
                'status' => 'paid',
                'note' => 'Diz que ja transferiu.',
                'force' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');

        $this->assertSame('pending_payment', $order->refresh()->status);
    }

    public function test_payment_confirmed_is_not_offered_as_a_manual_step(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.availableStatuses', [
                    'in_production', 'ready_to_ship', 'shipped', 'delivered', 'cancelled', 'refunded',
                ]));
    }

    /**
     * As encomendas que ja ficaram presas antes da correcao: estado `paid`
     * com o pagamento pendente. Marcar o pagamento tem de as pôr a andar.
     */
    public function test_an_order_left_in_payment_confirmed_by_hand_advances_when_the_payment_arrives(): void
    {
        $order = Order::factory()->create(['status' => 'paid']);
        OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id]);

        app(OrderService::class)->setPaymentStatus($order, 'paid', $this->admin);

        $this->assertSame('in_production', $order->refresh()->status);
    }

    public function test_the_detail_page_shows_items_and_a_merged_timeline(): void
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id]);

        $orders = app(OrderService::class);
        $orders->setPaymentStatus($order, 'paid', $this->admin);
        $orders->setItemProductionStatus($item, 'printing', $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/encomendas/show')
                ->has('order.items', 1)
                // Pagamento + paid + in_production + producao do item.
                ->has('order.timeline', 4)
                ->where('order.timeline.0.kind', 'item'));
    }

    public function test_tracking_and_notes_are_saved_without_touching_state(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.detalhes', $order), [
                'tracking_number' => 'CTT123456789PT',
                'tracking_url' => 'https://ctt.pt/track/CTT123456789PT',
                'admin_note' => 'Embrulhar com papel de seda.',
                'shipping_method_name' => 'CTT Expresso',
            ]);

        $order->refresh();
        $this->assertSame('CTT123456789PT', $order->tracking_number);
        $this->assertSame('pending_payment', $order->status);
    }

    public function test_non_admins_cannot_transition_orders(): void
    {
        $order = Order::factory()->paid()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('admin.encomendas.estado', $order), ['status' => 'shipped'])
            ->assertForbidden();

        $this->assertSame('paid', $order->refresh()->status);
    }
}
