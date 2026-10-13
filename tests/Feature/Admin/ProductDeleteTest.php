<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Apagar" um produto e um soft delete: sai da listagem e de todo o lado, mas
 * a linha fica — as encomendas e os movimentos de stock continuam a apontar
 * para alguma coisa. Arquivar (o `DELETE produtos/{product}` de sempre) e outra
 * conversa: o arquivado continua a vista, com a sua chip.
 */
class ProductDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->admin = User::factory()->admin()->create();
    }

    private function delete_(Product $product): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.produtos.apagar', $product))
            ->assertRedirect(route('admin.produtos.index'));
    }

    public function test_a_deleted_product_leaves_the_list_and_the_status_counts(): void
    {
        $gone = Product::factory()->create(['name' => 'Vaso Espiral', 'status' => 'active']);
        Product::factory()->create(['name' => 'Suporte', 'status' => 'active']);

        $this->delete_($gone);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Suporte')
                ->where('statusCounts', ['active' => 1])
                ->where('deletedCount', 1));
    }

    public function test_the_row_stays_archived_and_with_its_variants(): void
    {
        $product = Product::factory()->create(['status' => 'active']);
        $variant = Variant::factory()->for($product)->stock(4)->create();

        $this->delete_($product);

        // Arquivado alem de apagado: os filtros que ja excluem arquivados
        // (seletor de encomendas, stock baixo) excluem-no sem saber de nada.
        $row = Product::withTrashed()->findOrFail($product->id);
        $this->assertTrue($row->trashed());
        $this->assertSame('archived', $row->status);

        $this->assertSame(4, $variant->refresh()->stock);
    }

    public function test_the_page_of_a_deleted_product_is_gone(): void
    {
        $product = Product::factory()->create();

        $this->delete_($product);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.edit', $product->id))
            ->assertNotFound();
    }

    public function test_the_deleted_view_shows_only_the_deleted_ones(): void
    {
        $gone = Product::factory()->create(['name' => 'Vaso Espiral']);
        Product::factory()->create(['name' => 'Suporte']);

        $this->delete_($gone);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index', ['apagados' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Vaso Espiral')
                ->where('filters.apagados', true));
    }

    public function test_restoring_brings_it_back_as_a_draft(): void
    {
        $product = Product::factory()->create(['status' => 'active']);

        $this->delete_($product);

        $this->actingAs($this->admin)
            ->from(route('admin.produtos.index', ['apagados' => 1]))
            ->patch(route('admin.produtos.restaurar', $product->id))
            ->assertRedirect(route('admin.produtos.index', ['apagados' => 1]));

        // Rascunho, como qualquer restauro: voltar da reciclagem nao e
        // republicar na montra.
        $product->refresh();
        $this->assertFalse($product->trashed());
        $this->assertSame('draft', $product->status);
    }

    public function test_a_new_product_with_the_same_name_gets_its_own_slug(): void
    {
        $old = Product::factory()->create(['name' => 'Vaso Espiral', 'slug' => 'vaso-espiral']);

        $this->delete_($old);

        // O slug do apagado continua na tabela, com o indice unico por cima.
        // Sem o ver, o novo tentava o mesmo e rebentava na base de dados.
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                'name' => 'Vaso Espiral',
                'category_id' => null,
                'description' => '',
                'status' => 'draft',
                'featured' => false,
                'vat_rate' => 23,
                'fulfillment_mode' => 'in_stock',
                'production_time_days' => null,
                'allow_backorder' => false,
                'max_open_production_qty' => null,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('products', ['slug' => 'vaso-espiral-2', 'deleted_at' => null]);
    }

    public function test_the_history_still_knows_the_name_of_the_product(): void
    {
        $product = Product::factory()->create(['name' => 'Vaso Espiral']);
        $variant = Variant::factory()->for($product)->create();

        $this->delete_($product);

        // Movimentos de stock, alertas e o MCP leem `$variant->product->name`.
        // Com o produto apagado a relacao nao pode passar a devolver null.
        $this->assertSame('Vaso Espiral', Variant::query()->findOrFail($variant->id)->product->name);
    }

    public function test_an_old_order_still_opens(): void
    {
        $product = Product::factory()->create(['name' => 'Vaso Espiral']);
        $variant = Variant::factory()->for($product)->stock(5)->create(['price_cents' => 2490]);

        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $this->orderPayload($variant))
            ->assertRedirect();

        $this->delete_($product);

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.show', Order::query()->firstOrFail()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.items.0.productName', 'Vaso Espiral'));
    }

    public function test_a_manual_order_refuses_a_variant_of_a_deleted_product(): void
    {
        $product = Product::factory()->create(['name' => 'Vaso Espiral']);
        $variant = Variant::factory()->for($product)->stock(5)->create(['price_cents' => 2490]);

        $this->delete_($product);

        // A variante continua a existir por id (um rascunho de encomenda
        // guardado antes pode traze-la); vender um produto apagado nao.
        $this->actingAs($this->admin)
            ->post(route('admin.encomendas.store'), $this->orderPayload($variant))
            ->assertSessionHasErrors('items.0.variant_id');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(5, $variant->refresh()->stock);
    }

    public function test_the_category_stops_counting_it(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);

        $this->delete_($product);

        $this->assertSame(0, $category->products()->count());
    }

    public function test_archiving_is_still_archiving(): void
    {
        $product = Product::factory()->create(['status' => 'active']);

        // O `DELETE produtos/{product}` de sempre nao muda: arquiva, e o
        // arquivado continua na listagem.
        $this->actingAs($this->admin)
            ->delete(route('admin.produtos.destroy', $product));

        $this->assertFalse($product->refresh()->trashed());
        $this->assertSame('archived', $product->status);
    }

    public function test_non_admins_cannot_delete_a_product(): void
    {
        $product = Product::factory()->create(['status' => 'active']);

        $this->actingAs(User::factory()->create())
            ->delete(route('admin.produtos.apagar', $product))
            ->assertForbidden();

        $this->assertSame('active', $product->refresh()->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(Variant $variant): array
    {
        return [
            'user_id' => null,
            'customer_name' => 'Júlia Marques',
            'email' => 'julia@example.test',
            'phone' => '912345678',
            'nif' => '',
            'sales_channel' => 'vinted',
            'external_order_reference' => '',
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
            'draft_id' => null,
            'items' => [[
                'variant_id' => $variant->id,
                'product_name' => '',
                'variant_label' => '',
                'sku' => '',
                'unit_price' => '24.90',
                'qty' => 1,
                'price_override_reason' => '',
                'fulfillment_mode' => 'in_stock',
                'vat_rate' => 23,
            ]],
        ];
    }
}
