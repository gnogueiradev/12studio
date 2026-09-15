<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\OrderService;
use App\Support\OrderPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * O que o detalhe da encomenda desenha a partir dos dados: a barra de
 * progresso do topo e o historico separado por tipo de evento.
 */
class OrderDetailPresenterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->admin = User::factory()->admin()->create();
        $this->orders = app(OrderService::class);
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function steps(Order $order): array
    {
        return array_map(
            fn (array $step): array => [$step['status'], $step['state']],
            OrderPresenter::detail($order->refresh())['progress'],
        );
    }

    public function test_the_progress_follows_an_order_that_needs_printing(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->madeToOrder()->create(['order_id' => $order->id]);

        $this->orders->setPaymentStatus($order, 'paid', $this->admin);

        // Sem morada nao ha envio: o degrau nem aparece.
        $this->assertSame([
            ['pending_payment', 'done'],
            ['paid', 'done'],
            ['in_production', 'current'],
            ['ready_to_ship', 'todo'],
            ['delivered', 'todo'],
        ], $this->steps($order));
    }

    public function test_an_order_from_stock_does_not_show_a_production_step(): void
    {
        $order = Order::factory()->create([
            'shipping_address' => ['line1' => 'Rua A', 'postalCode' => '1000-001', 'city' => 'Lisboa'],
        ]);
        OrderItem::factory()->create(['order_id' => $order->id]);

        $this->orders->setPaymentStatus($order, 'paid', $this->admin);

        $this->assertSame([
            ['pending_payment', 'done'],
            ['paid', 'done'],
            ['ready_to_ship', 'current'],
            ['shipped', 'todo'],
            ['delivered', 'todo'],
        ], $this->steps($order));
    }

    public function test_a_cancelled_order_stops_where_it_was_and_shows_why(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $this->orders->transitionOrder($order, 'cancelled', $this->admin, 'Desistiu.');

        $this->assertSame([
            ['pending_payment', 'done'],
            ['cancelled', 'failed'],
        ], $this->steps($order));
    }

    public function test_payment_and_adjustment_events_are_unpacked_from_their_notes(): void
    {
        $order = Order::factory()->create([
            'subtotal_cents' => 2950,
            'shipping_cents' => 0,
            'total_cents' => 2950,
        ]);

        $this->orders->setPaymentStatus($order, 'paid', $this->admin);
        $this->orders->setAdjustment($order->refresh(), 950, 'Pago em filamento', $this->admin);

        $timeline = collect(OrderPresenter::detail($order->refresh())['timeline']);

        $payment = $timeline->firstWhere('category', 'payment');
        $this->assertSame('pending', $payment['paymentFrom']);
        $this->assertSame('paid', $payment['paymentTo']);
        $this->assertNull($payment['note']);

        $adjustment = $timeline->firstWhere('category', 'adjustment');
        $this->assertSame(2950, $adjustment['fromCents']);
        $this->assertSame(3900, $adjustment['toCents']);
        $this->assertSame('Pago em filamento', $adjustment['note']);

        // pending_payment (registo), paid e ready_to_ship sao degraus.
        $this->assertCount(2, $timeline->where('category', 'state'));
    }

    public function test_the_customer_order_count_only_exists_for_known_customers(): void
    {
        $customer = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $customer->id]);
        Order::factory()->count(2)->create(['user_id' => $customer->id]);

        $this->assertSame(3, OrderPresenter::detail($order)['customerOrdersCount']);
        $this->assertNull(OrderPresenter::detail(Order::factory()->create())['customerOrdersCount']);
    }
}
