<?php

namespace Tests\Feature\Admin;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ManualOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Variant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->admin = User::factory()->admin()->create();
        $this->variant = Variant::factory()->stock(5)->create([
            'product_id' => Product::factory()->create(['name' => 'Vaso Espiral'])->id,
            'price_cents' => 2490,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    private function payload(?array $items = null): array
    {
        return [
            'user_id' => null,
            'customer_name' => 'Júlia Marques',
            'email' => 'julia@example.test',
            'phone' => '912345678',
            'nif' => '123456789',
            'sales_channel' => 'vinted',
            'external_order_reference' => 'VNT-9981',
            'payment_method' => 'vinted',
            'payment_status' => 'pending',
            'shipping_price' => '3.50',
            'shipping_method_name' => 'CTT Expresso',
            'line1' => 'Rua das Flores 12',
            'line2' => '',
            'postal_code' => '4000-123',
            'city' => 'Porto',
            'country' => 'PT',
            'admin_note' => '',
            'send_confirmation' => false,
            // O formulario manda sempre o campo; so vem preenchido quando a
            // encomenda saiu de um rascunho (ver OrderDraftTest).
            'draft_id' => null,
            'items' => $items ?? [[
                'variant_id' => $this->variant->id,
                'product_name' => '',
                'variant_label' => '',
                'sku' => '',
                'unit_price' => '24.90',
                'qty' => 2,
                'price_override_reason' => '',
                'fulfillment_mode' => 'in_stock',
                'vat_rate' => 23,
            ]],
        ];
    }

    public function test_a_vinted_order_is_created_and_takes_stock(): void
    {
        // Relogio parado: o numero leva o ano gravado no POST, e sem isto a
        // assercao lia o ano outra vez — uma viragem de ano entre as duas
        // leituras chumbava o teste.
        $this->freezeTime();

        $year = now()->year;

        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $this->payload())
            ->assertRedirect();

        $order = Order::query()->firstOrFail();

        $this->assertSame($year.'-0001', $order->order_number);
        $this->assertSame('vinted', $order->sales_channel);
        $this->assertSame($this->admin->id, $order->created_by_user_id);
        // 2 x 24,90 = 49,80 + 3,50 de portes.
        $this->assertSame(4980, $order->subtotal_cents);
        $this->assertSame(5330, $order->total_cents);

        $this->assertSame(3, $this->variant->refresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $this->variant->id,
            'delta' => -2,
            'reason' => 'manual_order',
            'order_id' => $order->id,
        ]);
    }

    public function test_the_shipping_address_is_snapshotted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $this->payload());

        $order = Order::query()->firstOrFail();

        $this->assertSame('Rua das Flores 12', $order->shipping_address['line1']);
        $this->assertSame('4000-123', $order->shipping_address['postalCode']);
    }

    public function test_a_free_line_does_not_touch_stock(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $this->payload([[
                'variant_id' => null,
                'product_name' => 'Peça à medida',
                'variant_label' => 'Preto',
                'sku' => '',
                'unit_price' => '15.00',
                'qty' => 1,
                'price_override_reason' => '',
                'fulfillment_mode' => 'in_stock',
                'vat_rate' => 23,
            ]]));

        $this->assertDatabaseHas('order_items', [
            'product_name' => 'Peça à medida',
            'variant_id' => null,
            'unit_price_cents' => 1500,
        ]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_a_free_line_needs_a_description(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $this->payload([[
                'variant_id' => null,
                'product_name' => '',
                'variant_label' => '',
                'sku' => '',
                'unit_price' => '15.00',
                'qty' => 1,
                'price_override_reason' => '',
                'fulfillment_mode' => 'in_stock',
                'vat_rate' => 23,
            ]]))
            ->assertSessionHasErrors('items.0.product_name');
    }

    public function test_a_price_different_from_the_catalogue_needs_a_reason(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $this->payload([[
                'variant_id' => $this->variant->id,
                'product_name' => '',
                'variant_label' => '',
                'sku' => '',
                'unit_price' => '19.90',
                'qty' => 1,
                'price_override_reason' => '',
                'fulfillment_mode' => 'in_stock',
                'vat_rate' => 23,
            ]]))
            ->assertSessionHasErrors('items.0.price_override_reason');
    }

    public function test_a_justified_price_override_is_snapshotted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $this->payload([[
                'variant_id' => $this->variant->id,
                'product_name' => '',
                'variant_label' => '',
                'sku' => '',
                'unit_price' => '19.90',
                'qty' => 1,
                'price_override_reason' => 'Desconto acordado no Vinted.',
                'fulfillment_mode' => 'in_stock',
                'vat_rate' => 23,
            ]]));

        $this->assertDatabaseHas('order_items', [
            'unit_price_cents' => 1990,
            'catalog_unit_price_cents' => 2490,
            'price_override_reason' => 'Desconto acordado no Vinted.',
        ]);
    }

    public function test_an_order_beyond_available_stock_is_refused(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['qty'] = 99;

        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $payload)
            ->assertRedirect();

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $this->variant->refresh()->stock);
    }

    public function test_marking_it_paid_on_creation_advances_the_order(): void
    {
        $payload = $this->payload();
        $payload['payment_status'] = 'paid';

        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $payload);

        $order = Order::query()->firstOrFail();

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('ready_to_ship', $order->status);
        $this->assertNotNull($order->paid_at);
    }

    public function test_the_confirmation_email_is_optional(): void
    {
        $payload = $this->payload();
        $payload['send_confirmation'] = true;

        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $payload);

        // Os Mailables sao ShouldQueue — chegam a fila, nao ao mailer.
        Mail::assertQueued(OrderConfirmationMail::class);
    }

    public function test_the_create_page_offers_variants_and_customers(): void
    {
        User::factory()->create(['name' => 'Cliente Teste']);

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/encomendas/create')
                ->has('variants', 1)
                ->has('customers', 1)
                ->where('variants.0.sku', $this->variant->sku));
    }

    /**
     * O cliente de balcao: nome, um artigo, e mais nada. E o unico canal em que
     * o email nao e obrigatorio — a peca vai na mao, nao ha nada para enviar.
     */
    public function test_a_manual_channel_order_needs_only_a_name(): void
    {
        $payload = $this->payload();
        $payload['sales_channel'] = 'manual';
        $payload['payment_method'] = 'cash';

        foreach (['email', 'phone', 'nif', 'line1', 'line2', 'postal_code', 'city'] as $field) {
            $payload[$field] = '';
        }

        $payload['shipping_price'] = '0';
        $payload['shipping_method_name'] = '';

        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $payload)
            ->assertSessionHasNoErrors();

        $order = Order::query()->firstOrFail();

        $this->assertSame('Júlia Marques', $order->customer_name);
        // Nulo e nao string vazia: os dois a dizerem "sem email" obrigavam cada
        // envio de mail a testar os dois.
        $this->assertNull($order->email);
        $this->assertNull($order->shipping_address);
    }

    public function test_the_other_channels_still_need_an_email(): void
    {
        foreach (['vinted', 'instagram', 'website'] as $channel) {
            $payload = $this->payload();
            $payload['sales_channel'] = $channel;
            $payload['email'] = '';

            $this->actingAs($this->admin)
                ->post(route('admin.encomendas.store'), $payload)
                ->assertSessionHasErrors('email');
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_order_without_an_email_sends_nothing(): void
    {
        $payload = $this->payload();
        $payload['sales_channel'] = 'manual';
        $payload['email'] = '';
        // A checkbox nao aparece sem email, mas o pedido vem de fora: a guarda
        // tem de estar no servidor.
        $payload['send_confirmation'] = true;

        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $payload);

        Mail::assertNothingQueued();
    }

    /**
     * Uma venda em maos ainda pode ser expedida (o admin muda de ideias e vai
     * pelos CTT). Sem destinatario nao ha aviso, mas a encomenda avanca na
     * mesma: e o estado dela que manda no pipeline.
     */
    public function test_shipping_an_order_without_an_email_does_not_blow_up(): void
    {
        $payload = $this->payload();
        $payload['sales_channel'] = 'manual';
        $payload['email'] = '';
        $payload['payment_status'] = 'paid';

        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $payload);

        $order = Order::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.estado', $order), ['status' => 'shipped'])
            ->assertSessionHasNoErrors();

        $this->assertSame('shipped', $order->refresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_non_admins_cannot_create_orders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.encomendas.store'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('orders', 0);
    }
}
