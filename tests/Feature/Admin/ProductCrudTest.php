<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'Caixa Âmbar',
            'category_id' => null,
            'description' => 'Caixa impressa em PLA.',
            'status' => 'active',
            'featured' => true,
            'vat_rate' => 23,
            'fulfillment_mode' => 'made_to_order',
            'production_time_days' => 3,
            'allow_backorder' => false,
            'max_open_production_qty' => 10,
        ];
    }

    public function test_index_lists_products(): void
    {
        Product::query()->create([
            'name' => 'Vaso Espiral',
            'slug' => 'vaso-espiral',
            'status' => 'active',
            'fulfillment_mode' => 'in_stock',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/produtos/index')
                ->has('products', 1)
                ->where('products.0.name', 'Vaso Espiral'));
    }

    public function test_store_creates_product_with_ascii_slug(): void
    {
        $category = Category::query()->create(['name' => 'Decoração', 'slug' => 'decoracao']);

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('admin.produtos.index'));

        $this->assertDatabaseHas('products', [
            'name' => 'Caixa Âmbar',
            'slug' => 'caixa-ambar',
            'category_id' => $category->id,
            'fulfillment_mode' => 'made_to_order',
            'max_open_production_qty' => 10,
        ]);
    }

    public function test_store_rejects_invalid_fulfillment_mode_and_status(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'status' => 'inexistente',
                'fulfillment_mode' => 'teleporte',
            ])
            ->assertSessionHasErrors(['status', 'fulfillment_mode']);
    }

    public function test_update_edits_product(): void
    {
        $product = Product::query()->create([
            'name' => 'Vaso Espiral',
            'slug' => 'vaso-espiral',
            'status' => 'draft',
            'fulfillment_mode' => 'in_stock',
        ]);

        $this->actingAs($this->admin)->patch(route('admin.produtos.update', $product), [
            ...$this->validPayload(),
            'name' => 'Vaso Espiral XL',
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Vaso Espiral XL',
            'slug' => 'vaso-espiral-xl',
            'status' => 'active',
        ]);
    }

    public function test_destroy_archives_instead_of_deleting(): void
    {
        $product = Product::query()->create([
            'name' => 'Vaso Espiral',
            'slug' => 'vaso-espiral',
            'status' => 'active',
            'fulfillment_mode' => 'in_stock',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.produtos.destroy', $product))
            ->assertRedirect(route('admin.produtos.index'));

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'status' => 'archived',
        ]);
    }

    public function test_homepage_shows_only_active_products(): void
    {
        Product::query()->create([
            'name' => 'Ativo', 'slug' => 'ativo',
            'status' => 'active', 'fulfillment_mode' => 'in_stock',
        ]);
        Product::query()->create([
            'name' => 'Rascunho', 'slug' => 'rascunho',
            'status' => 'draft', 'fulfillment_mode' => 'in_stock',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home')
                ->has('products', 1)
                ->where('products.0.name', 'Ativo'));
    }

    public function test_non_admins_cannot_touch_products(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.produtos.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.produtos.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('products', ['name' => 'Caixa Âmbar']);
    }
}
