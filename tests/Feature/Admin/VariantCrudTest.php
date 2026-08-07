<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariantCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->product = Product::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'sku' => 'VASO-PLA-20',
            'size_label' => '20 cm',
            'price' => '24.90',
            'compare_at_price' => null,
            'stock' => 5,
            'low_stock_threshold' => 3,
            'is_default' => false,
            'active' => true,
        ];
    }

    public function test_store_converts_euros_to_cents(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.store', $this->product), [
                ...$this->validPayload(),
                'price' => '24,90',
            ])
            ->assertRedirect(route('admin.produtos.edit', $this->product));

        $this->assertDatabaseHas('variants', [
            'sku' => 'VASO-PLA-20',
            'price_cents' => 2490,
            'stock' => 5,
        ]);
    }

    public function test_initial_stock_is_recorded_as_a_movement(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.store', $this->product), $this->validPayload());

        $variant = Variant::query()->firstOrFail();

        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $variant->id,
            'delta' => 5,
            'reason' => 'initial',
            'created_by_user_id' => $this->admin->id,
        ]);
    }

    public function test_first_variant_becomes_the_default(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.store', $this->product), $this->validPayload());

        $this->assertTrue(Variant::query()->firstOrFail()->is_default);
    }

    public function test_marking_a_default_unsets_the_previous_one(): void
    {
        $first = Variant::factory()->isDefault()->create(['product_id' => $this->product->id]);
        $second = Variant::factory()->create(['product_id' => $this->product->id]);

        $this->actingAs($this->admin)
            ->patch(route('admin.variantes.update', $second), [
                ...$this->validPayload(),
                'sku' => $second->sku,
                'is_default' => true,
            ]);

        $this->assertFalse($first->refresh()->is_default);
        $this->assertTrue($second->refresh()->is_default);
    }

    public function test_editing_stock_records_a_manual_adjustment(): void
    {
        $variant = Variant::factory()->stock(10)->create(['product_id' => $this->product->id]);

        $this->actingAs($this->admin)
            ->patch(route('admin.variantes.update', $variant), [
                ...$this->validPayload(),
                'sku' => $variant->sku,
                'stock' => 7,
            ]);

        $this->assertSame(7, $variant->refresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $variant->id,
            'delta' => -3,
            'reason' => 'manual_adjust',
        ]);
    }

    public function test_duplicate_sku_is_rejected(): void
    {
        Variant::factory()->create(['sku' => 'VASO-PLA-20']);

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.store', $this->product), $this->validPayload())
            ->assertSessionHasErrors('sku');
    }

    public function test_compare_at_price_must_be_above_the_price(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.store', $this->product), [
                ...$this->validPayload(),
                'price' => '24.90',
                'compare_at_price' => '19.90',
            ])
            ->assertSessionHasErrors('compare_at_price');
    }

    public function test_destroy_archives_instead_of_deleting(): void
    {
        $variant = Variant::factory()->create(['product_id' => $this->product->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.variantes.destroy', $variant))
            ->assertRedirect(route('admin.produtos.edit', $this->product));

        $this->assertDatabaseHas('variants', [
            'id' => $variant->id,
            'active' => false,
        ]);
    }

    public function test_non_admins_cannot_touch_variants(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.produtos.variantes.store', $this->product), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('variants', ['sku' => 'VASO-PLA-20']);
    }
}
