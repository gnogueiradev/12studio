<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * As invariantes entre payment_status (o indicador financeiro) e status (o
 * pipeline de fulfilment) sao o coracao do OrderService. Se algum destes
 * testes cair, uma encomenda pode ser expedida sem estar paga.
 */
class OrderInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $orders;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->orders = app(OrderService::class);
        $this->admin = User::factory()->admin()->create();
    }

    private function unpaidOrder(): Order
    {
        $order = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        return $order;
    }

    public function test_an_unpaid_order_cannot_advance(): void
    {
        $order = $this->unpaidOrder();

        $this->expectException(RuntimeException::class);

        $this->orders->transitionOrder($order, 'ready_to_ship', $this->admin);
    }

    public function test_forcing_without_a_note_is_refused(): void
    {
        $order = $this->unpaidOrder();

        $this->expectException(RuntimeException::class);

        $this->orders->transitionOrder($order, 'paid', $this->admin, null, true);
    }

    public function test_forcing_with_a_note_records_the_author(): void
    {
        $order = $this->unpaidOrder();

        $this->orders->transitionOrder(
            $order,
            'in_production',
            $this->admin,
            'Cliente paga na entrega.',
            true,
        );

        $this->assertSame('in_production', $order->refresh()->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => 'in_production',
            'changed_by_user_id' => $this->admin->id,
            'note' => 'Cliente paga na entrega.',
        ]);
    }

    public function test_paying_an_in_stock_only_order_skips_production(): void
    {
        $order = $this->unpaidOrder();

        $this->orders->setPaymentStatus($order, 'paid', $this->admin);

        $this->assertSame('ready_to_ship', $order->refresh()->status);
    }

    public function test_paying_an_order_with_made_to_order_items_sends_it_to_production(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);
        OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id]);

        $this->orders->setPaymentStatus($order, 'paid', $this->admin);

        $this->assertSame('in_production', $order->refresh()->status);
    }

    public function test_a_failed_payment_cancels_the_order(): void
    {
        $order = $this->unpaidOrder();

        $this->orders->setPaymentStatus($order, 'failed', $this->admin);

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull($order->cancelled_at);
    }

    public function test_a_refund_moves_the_order_to_refunded(): void
    {
        $order = $this->unpaidOrder();

        $this->orders->setPaymentStatus($order, 'refunded', $this->admin);

        $this->assertSame('refunded', $order->refresh()->status);
    }

    public function test_transitions_are_forward_only(): void
    {
        $order = $this->unpaidOrder();
        $this->orders->setPaymentStatus($order, 'paid', $this->admin);

        $this->expectException(RuntimeException::class);

        $this->orders->transitionOrder($order->refresh(), 'paid', $this->admin);
    }

    public function test_a_cancelled_order_never_advances_again(): void
    {
        $order = $this->unpaidOrder();
        $this->orders->transitionOrder($order, 'cancelled', $this->admin);

        $this->expectException(RuntimeException::class);

        $this->orders->transitionOrder($order->refresh(), 'shipped', $this->admin);
    }

    public function test_items_in_stock_never_enter_the_production_board(): void
    {
        $order = $this->unpaidOrder();
        $item = $order->items()->firstOrFail();

        $this->expectException(RuntimeException::class);

        $this->orders->setItemProductionStatus($item, 'printing', $this->admin);
    }

    /**
     * Ao contrario do pipeline da encomenda, o de producao anda para tras:
     * uma peca que chumba no controlo de qualidade volta a impressora.
     */
    public function test_production_transitions_can_go_backwards(): void
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id]);

        $this->orders->setItemProductionStatus($item, 'quality_check', $this->admin);
        $this->orders->setItemProductionStatus($item->refresh(), 'printing', $this->admin);

        $this->assertSame('printing', $item->refresh()->production_status);
        $this->assertDatabaseHas('order_item_status_histories', [
            'order_item_id' => $item->id,
            'from_status' => 'quality_check',
            'to_status' => 'printing',
        ]);
    }

    public function test_a_shipped_order_never_reopens_production(): void
    {
        $order = Order::factory()->paid()->create(['status' => 'shipped']);
        $item = OrderItem::factory()->madeToOrder()->create([
            'order_id' => $order->id,
            'production_status' => 'ready',
        ]);

        $this->expectException(RuntimeException::class);

        $this->orders->setItemProductionStatus($item, 'printing', $this->admin);
    }

    /**
     * Largar um cartao na coluna onde ja estava e um no-op, nao um erro —
     * senao o drag & drop do quadro cuspia um toast a cada engano.
     */
    public function test_moving_an_item_to_its_own_status_does_nothing(): void
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id]);

        $this->orders->setItemProductionStatus($item, 'awaiting_production', $this->admin);

        $this->assertSame('awaiting_production', $item->refresh()->production_status);
        $this->assertDatabaseCount('order_item_status_histories', 0);
    }
}
