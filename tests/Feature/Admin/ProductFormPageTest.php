<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Tag;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * O formulario do produto deixou de ser um modal da listagem e voltou a ter
 * pagina: `/admin/produtos/novo` e `/admin/produtos/{produto}/editar`.
 *
 * O que isso paga: o formulario tem seis seccoes, uma galeria, uma matriz de
 * variantes e uma ficha de variante por dentro — dentro de um modal, a ficha da
 * variante tinha de tomar conta do ecra todo para caber, e cada foto carregada
 * dependia do `?editar={id}` no referrer para o `back()` acertar no sitio.
 */
class ProductFormPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_new_product_page_carries_the_lists_the_matrix_needs(): void
    {
        Category::query()->create(['name' => 'Decoração', 'slug' => 'decoracao']);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/produtos/form')
                ->has('categories', 1)
                ->has('colors')
                ->has('materials')
                ->has('printers')
                ->has('tagSuggestions')
                ->has('defaultVatRate')
                // A criar nao ha produto nenhum a editar — e o que poe a pagina
                // em modo de criacao, com a matriz de variantes.
                ->where('editing', null));
    }

    public function test_the_edit_page_carries_the_product_with_its_gallery_and_variants(): void
    {
        $category = Category::query()->create(['name' => 'Decoração', 'slug' => 'decoracao']);

        $product = Product::factory()->create([
            'name' => 'Vaso Espiral',
            'category_id' => $category->id,
            'description' => '<p>Impresso em PLA.</p>',
            'vat_rate' => 6,
        ]);
        $product->tags()->attach(
            Tag::query()->create(['name' => 'Natal', 'slug' => 'natal']),
        );

        $variant = Variant::factory()->create(['product_id' => $product->id]);
        ProductImage::factory()->create(['product_id' => $product->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.edit', $product))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/produtos/form')
                ->where('editing.product.id', $product->id)
                ->where('editing.product.name', 'Vaso Espiral')
                ->where('editing.product.categoryId', $category->id)
                ->where('editing.product.description', '<p>Impresso em PLA.</p>')
                ->where('editing.product.vatRate', 6)
                ->where('editing.product.tags', ['Natal'])
                ->has('editing.images', 1)
                ->has('editing.variants', 1)
                ->where('editing.variants.0.sku', $variant->sku)
                ->has('editing.suggestedSku'));
    }

    /**
     * O painel de custo da ficha de variante recarrega-se com
     * `only: ['pricing']` enquanto o admin escreve. Era a listagem que servia a
     * prop; agora e a pagina do formulario, senao o recarregamento parcial
     * ficava sem nada para recarregar.
     */
    public function test_the_edit_page_serves_the_pricing_panel(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.edit', $product))
            ->assertInertia(fn (Assert $page) => $page
                // Sem peso nem tempo no URL nao ha calculo — e assim que o
                // painel tem de abrir numa variante nova.
                ->where('pricing.result', null));
    }

    public function test_an_unknown_product_has_no_edit_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/produtos/99999/editar')
            ->assertNotFound();
    }

    public function test_non_admins_cannot_open_the_form(): void
    {
        $product = Product::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->get(route('admin.produtos.create'))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->get(route('admin.produtos.edit', $product))
            ->assertForbidden();
    }
}
