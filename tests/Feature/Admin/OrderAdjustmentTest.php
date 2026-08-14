<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ajuste ao valor final de uma encomenda ja registada: um desconto acordado
 * a posteriori ou um extra combinado a parte.
 *
 * A invariante que todos estes testes guardam e a mesma: o `total_cents` e
 * SEMPRE subtotal + portes + ajuste. E ele que o dashboard e a ficha de
 * cliente somam em SQL — se deixar de bater certo, a receita fica errada em
 * quatro sitios de uma vez.
 */
class OrderAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        // 44,00 € + 3,50 € de portes = 47,50 €. Numeros redondos para os
        // totais esperados se lerem de uma vez.
        $this->order = Order::factory()->create([
            'subtotal_cents' => 4400,
            'shipping_cents' => 350,
            'total_cents' => 4750,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'adjustment_price' => '-2.50',
            'adjustment_reason' => 'Desconto acordado no Instagram.',
        ], $overrides);
    }

    public function test_a_discount_lowers_the_total_and_leaves_the_parts_alone(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.ajuste', $this->order), $this->payload())
            ->assertSessionHasNoErrors();

        $this->order->refresh();

        $this->assertSame(-250, $this->order->adjustment_cents);
        $this->assertSame('Desconto acordado no Instagram.', $this->order->adjustment_reason);
        $this->assertSame(4500, $this->order->total_cents);
        // O ajuste vive ao lado das parcelas, nunca dentro delas: o subtotal
        // continua a ser a soma das linhas.
        $this->assertSame(4400, $this->order->subtotal_cents);
        $this->assertSame(350, $this->order->shipping_cents);
    }

    public function test_a_surcharge_raises_the_total(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.ajuste', $this->order), $this->payload([
                'adjustment_price' => '3,00',
                'adjustment_reason' => 'Embalagem para oferta.',
            ]))
            ->assertSessionHasNoErrors();

        $this->order->refresh();

        // Virgula decimal: o admin escreve como lhe apetece.
        $this->assertSame(300, $this->order->adjustment_cents);
        $this->assertSame(5050, $this->order->total_cents);
    }

    public function test_the_change_lands_on_the_timeline_with_both_values(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.ajuste', $this->order), $this->payload());

        $history = OrderStatusHistory::query()
            ->where('order_id', $this->order->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($this->admin->getKey(), $history->changed_by_user_id);
        // Nao e uma transicao de pipeline: o estado nao muda dos dois lados.
        $this->assertSame($history->from_status, $history->to_status);
        $this->assertStringContainsString('47.50 -> 45.00', (string) $history->note);
        $this->assertStringContainsString('Desconto acordado no Instagram.', (string) $history->note);
    }

    public function test_an_adjustment_without_a_reason_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.ajuste', $this->order), $this->payload([
                'adjustment_reason' => '',
            ]))
            ->assertSessionHasErrors('adjustment_reason');

        $this->assertSame(4750, $this->order->refresh()->total_cents);
    }

    public function test_an_adjustment_that_would_go_below_zero_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.ajuste', $this->order), $this->payload([
                'adjustment_price' => '-60.00',
            ]));

        $this->order->refresh();

        $this->assertSame(0, $this->order->adjustment_cents);
        $this->assertSame(4750, $this->order->total_cents);
    }

    public function test_a_closed_order_does_not_change_value(): void
    {
        $this->order->update(['status' => 'cancelled']);

        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.ajuste', $this->order), $this->payload());

        $this->order->refresh();

        $this->assertSame(0, $this->order->adjustment_cents);
        $this->assertSame(4750, $this->order->total_cents);
    }

    /**
     * Repor a zero apaga a justificacao com ela. Um motivo orfao, a explicar
     * um desconto que ja nao existe, e pior do que motivo nenhum.
     */
    public function test_resetting_to_zero_clears_the_reason(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.ajuste', $this->order), $this->payload());

        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.ajuste', $this->order), $this->payload([
                'adjustment_price' => '0',
                'adjustment_reason' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->order->refresh();

        $this->assertSame(0, $this->order->adjustment_cents);
        $this->assertNull($this->order->adjustment_reason);
        $this->assertSame(4750, $this->order->total_cents);
    }

    /**
     * O ecra de detalhe recebe o ajuste ja resolvido. Sem isto, o formulario
     * abria sempre a zero e a linha "Ajuste" nunca aparecia no resumo.
     */
    public function test_the_detail_page_carries_the_adjustment(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.ajuste', $this->order), $this->payload());

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.show', $this->order))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/encomendas/show')
                ->where('order.adjustmentCents', -250)
                ->where('order.adjustmentReason', 'Desconto acordado no Instagram.')
                ->where('order.subtotalCents', 4400)
                ->where('order.shippingCents', 350)
                ->where('order.totalCents', 4500)
            );
    }

    public function test_non_admins_cannot_adjust_an_order(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('admin.encomendas.ajuste', $this->order), $this->payload())
            ->assertForbidden();
    }
}
