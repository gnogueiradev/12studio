<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Filtros da listagem de categorias (irmao do ProductIndexTest).
 */
class CategoryIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_search_matches_the_name(): void
    {
        Category::query()->create(['name' => 'Iluminação', 'slug' => 'iluminacao']);
        Category::query()->create(['name' => 'Jogos', 'slug' => 'jogos']);

        $this->actingAs($this->admin)
            ->get(route('admin.categorias.index', ['search' => 'Ilumin']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('categories', 1)
                ->where('categories.0.slug', 'iluminacao'));
    }

    /**
     * O slug entra na pesquisa porque e o que se copia do URL da loja — e sem
     * acentos, o que faz dele a unica via que funciona para quem escreve
     * "decoracao" em vez de "Decoração".
     */
    public function test_search_matches_the_slug(): void
    {
        Category::query()->create(['name' => 'Decoração', 'slug' => 'decoracao']);
        Category::query()->create(['name' => 'Jogos', 'slug' => 'jogos']);

        $this->actingAs($this->admin)
            ->get(route('admin.categorias.index', ['search' => 'decoracao']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('categories', 1)
                ->where('categories.0.name', 'Decoração'));
    }

    public function test_status_filter_narrows_the_list(): void
    {
        Category::query()->create(['name' => 'Visivel', 'slug' => 'visivel']);
        Category::query()->create(['name' => 'Natal 2025', 'slug' => 'natal-2025', 'status' => 'archived']);

        $this->actingAs($this->admin)
            ->get(route('admin.categorias.index', ['status' => 'archived']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('categories', 1)
                ->where('categories.0.slug', 'natal-2025'));
    }

    /**
     * As contagens das chips sao calculadas SEM o filtro de estado. Se
     * respeitassem o proprio filtro, todas as chips excepto a activa mostravam
     * zero e deixavam de servir para navegar — que e a razao de existirem.
     */
    public function test_status_counts_ignore_the_status_filter(): void
    {
        Category::query()->create(['name' => 'Uma', 'slug' => 'uma']);
        Category::query()->create(['name' => 'Outra', 'slug' => 'outra']);
        Category::query()->create(['name' => 'Oculta', 'slug' => 'oculta', 'status' => 'hidden']);
        Category::query()->create(['name' => 'Natal 2025', 'slug' => 'natal-2025', 'status' => 'archived']);

        $this->actingAs($this->admin)
            ->get(route('admin.categorias.index', ['status' => 'archived']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('categories', 1)
                ->where('statusCounts.visible', 2)
                ->where('statusCounts.hidden', 1)
                ->where('statusCounts.archived', 1));
    }

    /**
     * A pesquisa, essa, tem mesmo de entrar nas contagens: com "natal" escrito
     * na caixa, uma chip a dizer "Visíveis 2" prometia duas linhas que a
     * pesquisa nunca ia mostrar.
     */
    public function test_status_counts_respect_the_search(): void
    {
        Category::query()->create(['name' => 'Natal 2025', 'slug' => 'natal-2025', 'status' => 'archived']);
        Category::query()->create(['name' => 'Jogos', 'slug' => 'jogos']);

        $this->actingAs($this->admin)
            ->get(route('admin.categorias.index', ['search' => 'natal']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statusCounts.archived', 1)
                ->missing('statusCounts.visible'));
    }

    public function test_index_counts_the_products_of_each_category(): void
    {
        $category = Category::query()->create(['name' => 'Jogos', 'slug' => 'jogos']);
        Product::factory()->count(2)->create(['category_id' => $category->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.categorias.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories.0.productsCount', 2));
    }
}
