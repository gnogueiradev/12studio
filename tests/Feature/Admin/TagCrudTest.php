<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TagCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function tag(string $name, string $scope = Tag::SCOPE_PRODUCT): Tag
    {
        return Tag::query()->create([
            'scope' => $scope,
            'name' => $name,
            'slug' => str($name)->slug()->value(),
        ]);
    }

    public function test_a_tag_is_created_in_the_chosen_scope(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.etiquetas.store'), [
                'scope' => Tag::SCOPE_CUSTOMER,
                'name' => 'Revendedor',
            ])
            ->assertRedirect(route('admin.etiquetas.index'));

        $this->assertDatabaseHas('tags', [
            'scope' => Tag::SCOPE_CUSTOMER,
            'name' => 'Revendedor',
            'slug' => 'revendedor',
        ]);
    }

    /**
     * Criar o que ja existe nao e erro: e o mesmo pedido feito duas vezes.
     */
    public function test_creating_an_existing_name_returns_the_existing_tag(): void
    {
        $this->tag('natal');

        $this->actingAs($this->admin)
            ->post(route('admin.etiquetas.store'), [
                'scope' => Tag::SCOPE_PRODUCT,
                'name' => 'NATAL',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('tags', 1);
        $this->assertSame('natal', Tag::query()->firstOrFail()->name);
    }

    public function test_a_name_without_letters_or_digits_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.etiquetas.store'), [
                'scope' => Tag::SCOPE_PRODUCT,
                'name' => '***',
            ])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('tags', 0);
    }

    public function test_renaming_to_a_free_name_keeps_the_same_row(): void
    {
        $tag = $this->tag('natl');

        $this->actingAs($this->admin)
            ->put(route('admin.etiquetas.update', $tag), ['name' => 'natal']);

        $this->assertDatabaseCount('tags', 1);
        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'name' => 'natal', 'slug' => 'natal']);
    }

    /**
     * O caso que a pagina existe para resolver: corrigir "natl" numa lista onde
     * "natal" ja existe reaponta os usos em vez de recusar.
     */
    public function test_renaming_onto_another_tag_merges_them(): void
    {
        $wrong = $this->tag('natl');
        $right = $this->tag('natal');

        $productA = Product::factory()->create();
        $productB = Product::factory()->create();

        $productA->tags()->attach($wrong->id);
        $productB->tags()->attach($right->id);

        $this->actingAs($this->admin)
            ->put(route('admin.etiquetas.update', $wrong), ['name' => 'natal']);

        $this->assertDatabaseMissing('tags', ['id' => $wrong->id]);
        $this->assertSame(['natal'], $productA->refresh()->tags->pluck('name')->all());
        $this->assertSame(['natal'], $productB->refresh()->tags->pluck('name')->all());
        $this->assertSame(2, $right->refresh()->products()->count());
    }

    /**
     * Um produto que ja tenha as DUAS etiquetas nao pode ficar com a linha do
     * pivot repetida — e o que o insertOrIgnore protege.
     */
    public function test_merging_a_tag_a_product_already_has_does_not_duplicate_the_pivot(): void
    {
        $wrong = $this->tag('natl');
        $right = $this->tag('natal');

        $product = Product::factory()->create();
        $product->tags()->attach([$wrong->id, $right->id]);

        $this->actingAs($this->admin)
            ->put(route('admin.etiquetas.update', $wrong), ['name' => 'natal']);

        $this->assertSame(['natal'], $product->refresh()->tags->pluck('name')->all());
        $this->assertDatabaseCount('product_tag', 1);
    }

    public function test_changing_only_the_case_does_not_delete_the_tag(): void
    {
        $tag = $this->tag('natal');

        $this->actingAs($this->admin)
            ->put(route('admin.etiquetas.update', $tag), ['name' => 'Natal']);

        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'name' => 'Natal', 'slug' => 'natal']);
        $this->assertDatabaseCount('tags', 1);
    }

    public function test_the_scope_cannot_be_changed_by_the_update(): void
    {
        $tag = $this->tag('natal');

        $this->actingAs($this->admin)
            ->put(route('admin.etiquetas.update', $tag), [
                'name' => 'natal',
                'scope' => Tag::SCOPE_ORDER,
            ]);

        $this->assertSame(Tag::SCOPE_PRODUCT, $tag->refresh()->scope);
    }

    public function test_deleting_a_tag_takes_its_pivot_rows_with_it(): void
    {
        $tag = $this->tag('urgente', Tag::SCOPE_ORDER);
        $order = Order::factory()->create();
        $order->tags()->attach($tag->id);

        $this->actingAs($this->admin)
            ->delete(route('admin.etiquetas.destroy', $tag));

        $this->assertDatabaseCount('tags', 0);
        $this->assertDatabaseCount('order_tag', 0);
        // A encomenda em si nao se toca: uma encomenda nunca se apaga.
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_pruning_deletes_only_the_tags_nobody_uses(): void
    {
        $used = $this->tag('natal');
        $this->tag('orfa-de-produto');
        $this->tag('orfa-de-cliente', Tag::SCOPE_CUSTOMER);

        Product::factory()->create()->tags()->attach($used->id);

        $this->actingAs($this->admin)
            ->delete(route('admin.etiquetas.limpar'))
            ->assertRedirect(route('admin.etiquetas.index'));

        $this->assertSame(['natal'], Tag::query()->pluck('name')->all());
    }

    /**
     * O `etiquetas/nao-usadas` e o `etiquetas/{tag}` partilham verbo e prefixo.
     * Se a ordem de registo se inverter, limpar passa a tentar apagar uma
     * etiqueta chamada "nao-usadas" e o 404 e silencioso.
     */
    public function test_the_prune_route_is_not_swallowed_by_the_resource(): void
    {
        $this->assertSame(
            'admin.etiquetas.limpar',
            app('router')->getRoutes()->match(
                Request::create('/admin/etiquetas/nao-usadas', 'DELETE'),
            )->getName(),
        );
    }

    public function test_the_listing_counts_uses_across_the_three_pivots(): void
    {
        $product = $this->tag('natal');
        $customer = $this->tag('revendedor', Tag::SCOPE_CUSTOMER);

        Product::factory()->create()->tags()->attach($product->id);
        User::factory()->create(['is_admin' => false])->tags()->attach($customer->id);

        $this->actingAs($this->admin)
            ->get(route('admin.etiquetas.index'))
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 2)
                ->where('stats.unusedCount', 0)
                ->where('stats.byScope.product', 1)
                ->where('stats.byScope.customer', 1)
                ->where('stats.byScope.order', 0));
    }

    public function test_non_admins_cannot_touch_tags(): void
    {
        $tag = $this->tag('natal');
        $customer = User::factory()->create(['is_admin' => false]);

        $this->actingAs($customer)->get(route('admin.etiquetas.index'))->assertForbidden();
        $this->actingAs($customer)
            ->post(route('admin.etiquetas.store'), ['scope' => Tag::SCOPE_PRODUCT, 'name' => 'x'])
            ->assertForbidden();
        $this->actingAs($customer)
            ->put(route('admin.etiquetas.update', $tag), ['name' => 'y'])
            ->assertForbidden();
        $this->actingAs($customer)
            ->delete(route('admin.etiquetas.destroy', $tag))
            ->assertForbidden();
        $this->actingAs($customer)->delete(route('admin.etiquetas.limpar'))->assertForbidden();

        $this->assertDatabaseCount('tags', 1);
    }
}
