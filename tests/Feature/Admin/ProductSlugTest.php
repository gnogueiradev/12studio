<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSlugTest extends TestCase
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
            'name' => 'Vaso Ondulado',
            'status' => 'draft',
            'vat_rate' => 23,
            'fulfillment_mode' => 'in_stock',
            ...$overrides,
        ];
    }

    public function test_an_empty_slug_is_generated_from_the_name(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), $this->payload(['slug' => '']));

        $this->assertSame('vaso-ondulado', Product::query()->firstOrFail()->slug);
    }

    public function test_a_hand_written_slug_is_kept(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), $this->payload(['slug' => 'vaso-onda-grande']));

        $this->assertSame('vaso-onda-grande', Product::query()->firstOrFail()->slug);
    }

    public function test_a_colliding_slug_gets_a_numeric_suffix(): void
    {
        Product::factory()->create(['slug' => 'vaso-ondulado']);

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), $this->payload(['slug' => 'vaso-ondulado']));

        $this->assertDatabaseHas('products', ['slug' => 'vaso-ondulado-2']);
    }

    /**
     * O slug e um URL que ja pode andar partilhado: renomear o produto nao
     * lhe pode mexer.
     */
    public function test_renaming_does_not_touch_a_slug_that_was_kept(): void
    {
        $product = Product::factory()->create(['slug' => 'vaso-onda-grande']);

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.update', $product), $this->payload([
                'name' => 'Vaso Ondulado Grande',
                'slug' => 'vaso-onda-grande',
            ]));

        $this->assertSame('vaso-onda-grande', $product->refresh()->slug);
    }

    public function test_clearing_the_slug_regenerates_it_from_the_name(): void
    {
        $product = Product::factory()->create(['slug' => 'antigo']);

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.update', $product), $this->payload([
                'name' => 'Taça Redonda',
                'slug' => '',
            ]));

        $this->assertSame('taca-redonda', $product->refresh()->slug);
    }

    public function test_an_invalid_slug_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), $this->payload(['slug' => 'Vaso Ondulado!']))
            ->assertSessionHasErrors('slug');

        $this->assertDatabaseCount('products', 0);
    }
}
