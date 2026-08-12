<?php

namespace Tests\Feature\Admin;

use App\Models\OrderDraft;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderDraftTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Variant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->variant = Variant::factory()->stock(5)->create([
            'product_id' => Product::factory()->create(['name' => 'Vaso Espiral'])->id,
            'price_cents' => 2490,
        ]);
    }

    /**
     * O formulario a meio: sem email, com uma linha so.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'user_id' => null,
            'customer_name' => 'Júlia Marques',
            'email' => '',
            'phone' => '',
            'nif' => '',
            'sales_channel' => 'instagram',
            'external_order_reference' => '',
            'payment_method' => 'mbway',
            'payment_status' => 'pending',
            'shipping_price' => '3.50',
            'shipping_method_name' => '',
            'line1' => '',
            'line2' => '',
            'postal_code' => '',
            'city' => '',
            'country' => 'PT',
            'admin_note' => '',
            'send_confirmation' => false,
            'items' => [[
                'variant_id' => $this->variant->id,
                'product_name' => 'Vaso Espiral',
                'variant_label' => 'Preto',
                'sku' => $this->variant->sku,
                'unit_price' => '24.90',
                'qty' => 2,
                'price_override_reason' => '',
                'fulfillment_mode' => 'in_stock',
                'vat_rate' => 23,
            ]],
        ];
    }

    public function test_an_incomplete_form_is_saved_as_a_draft(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.rascunhos.store'), $this->payload())
            ->assertRedirect();

        $draft = OrderDraft::query()->firstOrFail();

        $this->assertSame($this->admin->id, $draft->created_by_user_id);
        $this->assertSame('Júlia Marques', $draft->customer_name);
        // 2 x 24,90 = 49,80 + 3,50 de portes.
        $this->assertSame(5330, $draft->total_cents);
        $this->assertSame('24.90', $draft->payload['items'][0]['unit_price']);
    }

    /**
     * O que separa um rascunho de uma encomenda: nao gasta numero da
     * sequencia anual e nao mexe em stock.
     */
    public function test_saving_a_draft_touches_neither_orders_nor_stock(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.rascunhos.store'), $this->payload());

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_sequences', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(5, $this->variant->refresh()->stock);
    }

    public function test_saving_again_updates_the_same_draft(): void
    {
        $draft = $this->draftFor($this->admin);

        $payload = $this->payload();
        $payload['customer_name'] = 'Rui Tavares';

        $this->actingAs($this->admin)
            ->put(route('admin.encomendas.rascunhos.update', $draft), $payload)
            ->assertRedirect();

        $this->assertDatabaseCount('order_drafts', 1);
        $this->assertSame('Rui Tavares', $draft->refresh()->customer_name);
    }

    public function test_resuming_a_draft_opens_the_form_already_filled(): void
    {
        $draft = $this->draftFor($this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.rascunhos.edit', $draft))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/encomendas/create')
                ->where('draft.id', $draft->id)
                ->where('draft.payload.customer_name', 'Júlia Marques')
                ->has('draft.payload.items', 1)
                ->has('variants', 1));
    }

    public function test_creating_the_order_from_a_draft_removes_the_draft(): void
    {
        $draft = $this->draftFor($this->admin);

        $payload = $this->payload();
        $payload['email'] = 'julia@example.test';
        $payload['draft_id'] = $draft->id;

        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $payload)
            ->assertRedirect();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_drafts', 0);
    }

    /**
     * A encomenda e o que interessa: se o rascunho ja tinha desaparecido
     * noutro separador, a criacao segue na mesma.
     */
    public function test_an_unknown_draft_id_does_not_block_the_order(): void
    {
        $payload = $this->payload();
        $payload['email'] = 'julia@example.test';
        $payload['draft_id'] = 9999;

        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $payload)
            ->assertRedirect();

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_a_draft_can_be_deleted(): void
    {
        $draft = $this->draftFor($this->admin);

        $this->actingAs($this->admin)
            ->delete(route('admin.encomendas.rascunhos.destroy', $draft))
            ->assertRedirect(route('admin.encomendas.rascunhos.index'));

        $this->assertDatabaseCount('order_drafts', 0);
    }

    public function test_the_listing_only_shows_my_own_drafts(): void
    {
        $this->draftFor($this->admin);
        $this->draftFor(User::factory()->admin()->create());

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.rascunhos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/encomendas/rascunhos/index')
                ->has('drafts', 1));
    }

    /**
     * Um rascunho e trabalho a meio de uma pessoa: para outro admin nao
     * existe, nem para ler, nem para gravar, nem para apagar.
     */
    public function test_another_admins_draft_is_out_of_reach(): void
    {
        $draft = $this->draftFor(User::factory()->admin()->create());

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.rascunhos.edit', $draft))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->put(route('admin.encomendas.rascunhos.update', $draft), $this->payload())
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->delete(route('admin.encomendas.rascunhos.destroy', $draft))
            ->assertNotFound();

        $this->assertDatabaseCount('order_drafts', 1);
    }

    public function test_the_order_listing_counts_my_drafts(): void
    {
        $this->draftFor($this->admin);
        $this->draftFor(User::factory()->admin()->create());

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('draftsCount', 1));
    }

    public function test_non_admins_cannot_save_drafts(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.encomendas.rascunhos.store'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('order_drafts', 0);
    }

    private function draftFor(User $author): OrderDraft
    {
        return OrderDraft::query()->create([
            'created_by_user_id' => $author->id,
            'customer_name' => 'Júlia Marques',
            'total_cents' => 5330,
            'payload' => $this->payload(),
        ]);
    }
}
