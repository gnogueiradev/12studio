<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_index_lists_products_newest_first(): void
    {
        Product::factory()->create(['name' => 'Antigo', 'created_at' => now()->subDay()]);
        Product::factory()->create(['name' => 'Recente']);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/produtos/index')
                ->has('products.data', 2)
                ->where('products.data.0.name', 'Recente'));
    }

    public function test_the_list_is_paginated(): void
    {
        Product::factory()->count(25)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 20)
                ->where('products.total', 25)
                ->where('products.last_page', 2));
    }

    public function test_filters_narrow_the_list(): void
    {
        $decoracao = Category::query()->create(['name' => 'Decoração', 'slug' => 'decoracao']);

        Product::factory()->create(['category_id' => $decoracao->id]);
        Product::factory()->madeToOrder()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index', ['category_id' => $decoracao->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.category', 'Decoração')
                ->where('filters.category_id', (string) $decoracao->id));

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index', ['fulfillment_mode' => 'made_to_order']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.fulfillmentMode', 'made_to_order'));
    }

    public function test_search_matches_the_name(): void
    {
        Product::factory()->create(['name' => 'Vaso ondulado']);
        Product::factory()->create(['name' => 'Suporte telemovel']);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index', ['search' => 'ondulado']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Vaso ondulado'));
    }

    public function test_search_also_matches_the_reference_of_a_variant(): void
    {
        $product = Product::factory()->create(['name' => 'Vaso ondulado']);
        Variant::factory()->for($product)->create(['sku' => 'VAS-001']);
        Product::factory()->create(['name' => 'Suporte telemovel']);

        // A referencia que o admin copia de uma etiqueta e o SKU da variante —
        // o produto nao tem nenhuma. Procurar so pelo nome deixava de fora a
        // via mais rapida de chegar a um produto.
        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index', ['search' => 'VAS-001']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Vaso ondulado'));
    }

    public function test_status_counts_ignore_the_status_filter(): void
    {
        Product::factory()->count(2)->create(['status' => 'draft']);
        Product::factory()->count(3)->create(['status' => 'active']);

        // A lista obedece ao filtro, as contagens das chips nao: se
        // obedecessem, todas as chips excepto a activa mostravam zero e
        // deixavam de servir para navegar.
        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index', ['status' => 'active']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 3)
                ->where('statusCounts.active', 3)
                ->where('statusCounts.draft', 2));
    }

    public function test_status_counts_respect_the_other_filters(): void
    {
        Product::factory()->madeToOrder()->create(['status' => 'active']);
        Product::factory()->count(2)->create(['status' => 'active']);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index', ['fulfillment_mode' => 'made_to_order']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('statusCounts.active', 1));
    }

    public function test_rows_carry_the_details_of_the_default_variant(): void
    {
        $product = Product::factory()->create();
        Variant::factory()->isDefault()->for($product)->create([
            'sku' => 'VAS-001',
            'price_cents' => 2900,
            'filament_weight_grams' => 84,
            'printing_time_minutes' => 130,
            'stock' => 5,
            'reserved_stock' => 2,
        ]);
        Variant::factory()->for($product)->create([
            'price_cents' => 3400,
            'stock' => 4,
            'reserved_stock' => 0,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data.0.sku', 'VAS-001')
                ->where('products.data.0.priceCents', 2900)
                ->where('products.data.0.filamentWeightGrams', 84)
                ->where('products.data.0.printingTimeMinutes', 130)
                ->where('products.data.0.variantsCount', 2)
                // Pronto a sair hoje: (5 - 2) + (4 - 0), somado em TODAS as
                // variantes e nao so na default.
                ->where('products.data.0.readyStock', 7));
    }

    public function test_a_product_without_variants_carries_nulls(): void
    {
        Product::factory()->create(['status' => 'draft']);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data.0.sku', null)
                ->where('products.data.0.priceCents', null)
                ->where('products.data.0.variantsCount', 0)
                ->where('products.data.0.readyStock', 0));
    }

    public function test_the_page_carries_the_lists_the_new_product_modal_needs(): void
    {
        Category::query()->create(['name' => 'Decoração', 'slug' => 'decoracao']);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('categories', 1)
                ->has('colorGroups')
                ->has('defaultVatRate'));
    }

    public function test_non_admins_cannot_list_products(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.produtos.index'))
            ->assertForbidden();
    }
}
