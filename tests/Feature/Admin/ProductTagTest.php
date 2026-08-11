<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTagTest extends TestCase
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
            'name' => 'Vaso ondulado',
            'status' => 'draft',
            'vat_rate' => 23,
            'fulfillment_mode' => 'in_stock',
            ...$overrides,
        ];
    }

    public function test_new_tags_are_created_and_attached(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), $this->payload([
                'tags' => ['natal', 'presente'],
            ]))
            ->assertRedirect(route('admin.produtos.index'));

        $product = Product::query()->firstOrFail();

        $this->assertSame(['natal', 'presente'], $product->tags->pluck('name')->all());
        $this->assertDatabaseHas('tags', ['slug' => 'natal']);
    }

    public function test_an_existing_tag_is_reused_not_duplicated(): void
    {
        Tag::query()->create(['name' => 'Natal', 'slug' => 'natal']);

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), $this->payload([
                'tags' => ['natal'],
            ]));

        $this->assertDatabaseCount('tags', 1);
        // Fica o nome original: quem a criou escolheu a forma.
        $this->assertSame('Natal', Tag::query()->firstOrFail()->name);
    }

    /**
     * "Natal" e "natal" dao o mesmo slug — desambiguar so na BD faria o
     * firstOrCreate devolver a mesma linha duas vezes na mesma gravacao.
     */
    public function test_tags_differing_only_in_case_collapse_into_one(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), $this->payload([
                'tags' => ['Natal', 'natal', 'NATAL'],
            ]));

        $this->assertDatabaseCount('tags', 1);
        $this->assertCount(1, Product::query()->firstOrFail()->tags);
    }

    public function test_updating_replaces_the_tag_set(): void
    {
        $product = Product::factory()->create();
        $product->tags()->attach(Tag::query()->create(['name' => 'velha', 'slug' => 'velha']));

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.update', $product), $this->payload([
                'name' => $product->name,
                'tags' => ['nova'],
            ]));

        $this->assertSame(['nova'], $product->refresh()->tags->pluck('name')->all());
    }

    public function test_sending_no_tags_clears_them(): void
    {
        $product = Product::factory()->create();
        $product->tags()->attach(Tag::query()->create(['name' => 'velha', 'slug' => 'velha']));

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.update', $product), $this->payload([
                'name' => $product->name,
                'tags' => [],
            ]));

        $this->assertCount(0, $product->refresh()->tags);
    }

    public function test_more_than_twenty_tags_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), $this->payload([
                'tags' => array_map(fn (int $i): string => "tag-{$i}", range(1, 21)),
            ]))
            ->assertSessionHasErrors('tags');
    }
}
