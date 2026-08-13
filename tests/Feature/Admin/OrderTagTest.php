<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OrderTagTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->order = Order::factory()->create();
    }

    /**
     * O form "Envio e notas" submete os campos todos de uma vez — reproduzi-lo
     * aqui e o que garante que as etiquetas nao partem os outros.
     *
     * @param  array<int, string>  $tags
     * @return array<string, mixed>
     */
    private function payload(array $tags): array
    {
        return [
            'admin_note' => 'Entrega em mão.',
            'tracking_number' => null,
            'tracking_url' => null,
            'shipping_method_name' => 'CTT Expresso',
            'tags' => $tags,
        ];
    }

    private function patchDetails(array $tags): TestResponse
    {
        return $this->actingAs($this->admin)
            ->patch(route('admin.encomendas.detalhes', $this->order), $this->payload($tags));
    }

    public function test_new_tags_are_created_and_attached(): void
    {
        $this->patchDetails(['urgente', 'oferta']);

        $this->assertSame(['oferta', 'urgente'], $this->order->refresh()->tags->pluck('name')->all());
        $this->assertDatabaseHas('tags', ['slug' => 'urgente', 'scope' => Tag::SCOPE_ORDER]);
    }

    public function test_the_rest_of_the_form_still_saves(): void
    {
        $this->patchDetails(['urgente']);

        $this->assertSame('CTT Expresso', $this->order->refresh()->shipping_method_name);
        $this->assertSame('Entrega em mão.', $this->order->admin_note);
    }

    public function test_saving_without_tags_clears_them(): void
    {
        $this->patchDetails(['urgente']);
        $this->patchDetails([]);

        $this->assertCount(0, $this->order->refresh()->tags);
    }

    public function test_an_order_tag_never_reuses_a_product_tag_with_the_same_name(): void
    {
        Tag::query()->create(['scope' => Tag::SCOPE_PRODUCT, 'name' => 'urgente', 'slug' => 'urgente']);

        $this->patchDetails(['urgente']);

        $this->assertSame(Tag::SCOPE_ORDER, $this->order->refresh()->tags->firstOrFail()->scope);
        $this->assertDatabaseCount('tags', 2);
    }

    public function test_suggestions_on_the_detail_page_are_order_only(): void
    {
        Tag::query()->create(['scope' => Tag::SCOPE_CUSTOMER, 'name' => 'revendedor', 'slug' => 'revendedor']);
        Tag::query()->create(['scope' => Tag::SCOPE_ORDER, 'name' => 'urgente', 'slug' => 'urgente']);

        $this->actingAs($this->admin)
            ->get(route('admin.encomendas.show', $this->order))
            ->assertInertia(fn ($page) => $page->where('tagSuggestions', ['urgente']));
    }

    public function test_more_than_twenty_tags_is_rejected(): void
    {
        $tags = array_map(fn (int $index): string => "etiqueta-{$index}", range(1, 21));

        $this->patchDetails($tags)->assertSessionHasErrors('tags');
    }

    public function test_non_admins_cannot_tag_an_order(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->patch(route('admin.encomendas.detalhes', $this->order), $this->payload(['urgente']))
            ->assertForbidden();

        $this->assertDatabaseCount('tags', 0);
    }
}
