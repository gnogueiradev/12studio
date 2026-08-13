<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O filtro `?tag=` das tres listagens.
 *
 * O caso que interessa esta no fim: com "natal" a existir nos tres ambitos, o
 * filtro de cada listagem so pode ver o seu. O slug e igual nos tres, portanto e
 * o `scope` da relacao — e nao a query — que faz a separacao.
 */
class TagFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function tag(string $scope, string $name = 'natal'): Tag
    {
        return Tag::query()->create([
            'scope' => $scope,
            'name' => $name,
            'slug' => str($name)->slug()->value(),
        ]);
    }

    public function test_products_are_filtered_by_tag(): void
    {
        $tag = $this->tag(Tag::SCOPE_PRODUCT);

        $tagged = Product::factory()->create(['name' => 'Vaso de natal']);
        Product::factory()->create(['name' => 'Vaso liso']);

        $tagged->tags()->attach($tag->id);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index', ['tag' => 'natal']))
            ->assertInertia(fn ($page) => $page
                ->where('products.total', 1)
                ->where('products.data.0.name', 'Vaso de natal'));
    }

    public function test_customers_are_filtered_by_tag(): void
    {
        $tag = $this->tag(Tag::SCOPE_CUSTOMER, 'revendedor');

        $tagged = User::factory()->create(['is_admin' => false, 'name' => 'Ana Marques']);
        User::factory()->create(['is_admin' => false, 'name' => 'Bruno Silva']);

        $tagged->tags()->attach($tag->id);

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index', ['tag' => 'revendedor']))
            ->assertInertia(fn ($page) => $page
                ->where('customers.total', 1)
                ->where('customers.data.0.name', 'Ana Marques'));
    }

    public function test_orders_are_filtered_by_tag(): void
    {
        $tag = $this->tag(Tag::SCOPE_ORDER, 'urgente');

        $tagged = Order::factory()->create();
        Order::factory()->create();

        $tagged->tags()->attach($tag->id);

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.index', ['tag' => 'urgente']))
            ->assertInertia(fn ($page) => $page
                ->where('orders.total', 1)
                ->where('orders.data.0.orderNumber', $tagged->order_number));
    }

    /**
     * O filtro entra DENTRO do $scoped, portanto as chips de estado contam so o
     * que ele deixa passar. Se entrasse depois, as chips continuavam a anunciar
     * numeros que a tabela filtrada nao mostra.
     */
    public function test_the_status_chips_count_within_the_tag_filter(): void
    {
        $tag = $this->tag(Tag::SCOPE_PRODUCT);

        Product::factory()->create(['status' => 'draft'])->tags()->attach($tag->id);
        Product::factory()->create(['status' => 'draft']);
        Product::factory()->create(['status' => 'active']);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index', ['tag' => 'natal']))
            ->assertInertia(fn ($page) => $page
                ->where('statusCounts.draft', 1)
                ->missing('statusCounts.active'));
    }

    /**
     * O ponto todo do `scope`, visto do lado da leitura.
     */
    public function test_the_same_slug_in_three_scopes_never_crosses_listings(): void
    {
        $productTag = $this->tag(Tag::SCOPE_PRODUCT);
        $customerTag = $this->tag(Tag::SCOPE_CUSTOMER);
        $orderTag = $this->tag(Tag::SCOPE_ORDER);

        Product::factory()->create()->tags()->attach($productTag->id);
        User::factory()->create(['is_admin' => false])->tags()->attach($customerTag->id);
        Order::factory()->create()->tags()->attach($orderTag->id);

        // Mais um de cada SEM etiqueta, para o filtro ter mesmo de excluir.
        Product::factory()->create();
        User::factory()->create(['is_admin' => false]);
        Order::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index', ['tag' => 'natal']))
            ->assertInertia(fn ($page) => $page->where('products.total', 1));

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.index', ['tag' => 'natal']))
            ->assertInertia(fn ($page) => $page->where('customers.total', 1));

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.index', ['tag' => 'natal']))
            ->assertInertia(fn ($page) => $page->where('orders.total', 1));
    }

    /**
     * Uma etiqueta sem uso e uma opcao que so pode dar zero resultados.
     */
    public function test_the_filter_options_leave_out_unused_tags(): void
    {
        $used = $this->tag(Tag::SCOPE_PRODUCT);
        $this->tag(Tag::SCOPE_PRODUCT, 'nunca-usada');
        // Do ambito errado: nao pode aparecer nas opcoes dos produtos.
        $this->tag(Tag::SCOPE_CUSTOMER, 'revendedor');

        Product::factory()->create()->tags()->attach($used->id);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index'))
            ->assertInertia(fn ($page) => $page
                ->where('tagOptions', [['value' => 'natal', 'label' => 'natal']]));
    }

    /**
     * Desde que as etiquetas deixaram de ser exclusivas do catalogo, sugerir
     * todas era oferecer "revendedor" ao classificar um vaso.
     */
    public function test_the_product_suggestions_are_product_only(): void
    {
        $this->tag(Tag::SCOPE_PRODUCT);
        $this->tag(Tag::SCOPE_CUSTOMER, 'revendedor');
        $this->tag(Tag::SCOPE_ORDER, 'urgente');

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index'))
            ->assertInertia(fn ($page) => $page->where('tagSuggestions', ['natal']));
    }

    public function test_an_unknown_tag_returns_nothing_instead_of_everything(): void
    {
        Product::factory()->count(3)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index', ['tag' => 'nao-existe']))
            ->assertInertia(fn ($page) => $page->where('products.total', 0));
    }
}
