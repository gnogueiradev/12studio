<?php

namespace Tests\Feature\Admin;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTagTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Ana Marques',
            'customer_type' => 'particular',
            'country' => 'PT',
            ...$overrides,
        ];
    }

    private function customer(): User
    {
        return User::factory()->create(['is_admin' => false]);
    }

    public function test_new_tags_are_created_and_attached_on_store(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.clientes.store'), $this->payload([
                'tags' => ['revendedor', 'atacado'],
            ]));

        $customer = User::query()->where('name', 'Ana Marques')->firstOrFail();

        $this->assertSame(['atacado', 'revendedor'], $customer->tags->pluck('name')->all());
        $this->assertDatabaseHas('tags', [
            'slug' => 'revendedor',
            'scope' => Tag::SCOPE_CUSTOMER,
        ]);
    }

    public function test_updating_replaces_the_tag_set(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin)
            ->patch(route('admin.clientes.update', $customer), $this->payload(['tags' => ['velha']]));

        $this->actingAs($this->admin)
            ->patch(route('admin.clientes.update', $customer), $this->payload(['tags' => ['nova']]));

        $this->assertSame(['nova'], $customer->refresh()->tags->pluck('name')->all());
    }

    public function test_sending_no_tags_clears_them(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin)
            ->patch(route('admin.clientes.update', $customer), $this->payload(['tags' => ['revendedor']]));

        $this->actingAs($this->admin)
            ->patch(route('admin.clientes.update', $customer), $this->payload(['tags' => []]));

        $this->assertCount(0, $customer->refresh()->tags);
        // A etiqueta em si nao desaparece por deixar de ter uso: apaga-se na
        // pagina de gestao, de proposito, nao por efeito lateral.
        $this->assertDatabaseCount('tags', 1);
    }

    /**
     * O ponto todo do `scope`. Sem ele, o firstOrCreate por slug devolvia a
     * etiqueta do catalogo e as duas listas de sugestoes fundiam-se.
     */
    public function test_a_customer_tag_never_reuses_a_product_tag_with_the_same_name(): void
    {
        $productTag = Tag::query()->create([
            'scope' => Tag::SCOPE_PRODUCT,
            'name' => 'natal',
            'slug' => 'natal',
        ]);

        $customer = $this->customer();

        $this->actingAs($this->admin)
            ->patch(route('admin.clientes.update', $customer), $this->payload(['tags' => ['natal']]));

        $attached = $customer->refresh()->tags->firstOrFail();

        $this->assertNotSame($productTag->id, $attached->id);
        $this->assertSame(Tag::SCOPE_CUSTOMER, $attached->scope);
        $this->assertDatabaseCount('tags', 2);
    }

    public function test_suggestions_on_the_edit_page_are_customer_only(): void
    {
        Tag::query()->create(['scope' => Tag::SCOPE_PRODUCT, 'name' => 'natal', 'slug' => 'natal']);
        Tag::query()->create(['scope' => Tag::SCOPE_CUSTOMER, 'name' => 'revendedor', 'slug' => 'revendedor']);

        $this->actingAs($this->admin)
            ->get(route('admin.clientes.edit', $this->customer()))
            ->assertInertia(fn ($page) => $page->where('tagSuggestions', ['revendedor']));
    }

    public function test_more_than_twenty_tags_is_rejected(): void
    {
        $tags = array_map(fn (int $index): string => "etiqueta-{$index}", range(1, 21));

        $this->actingAs($this->admin)
            ->patch(route('admin.clientes.update', $this->customer()), $this->payload(['tags' => $tags]))
            ->assertSessionHasErrors('tags');
    }

    public function test_non_admins_cannot_tag_a_customer(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)
            ->patch(route('admin.clientes.update', $customer), $this->payload(['tags' => ['revendedor']]))
            ->assertForbidden();

        $this->assertDatabaseCount('tags', 0);
    }
}
