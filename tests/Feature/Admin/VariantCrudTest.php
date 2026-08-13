<?php

namespace Tests\Feature\Admin;

use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
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
            'normal_price' => '24.90',
            'sale_price' => null,
            'wholesale_price' => null,
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
                'normal_price' => '24,90',
            ])
            // O produto ja nao tem pagina de edicao: volta-se a listagem com o
            // modal aberto no produto, que e onde as variantes se veem.
            ->assertRedirect(route('admin.produtos.index', ['editar' => $this->product->id]));

        $this->assertDatabaseHas('variants', [
            'sku' => 'VASO-PLA-20',
            'price_cents' => 2490,
            'compare_at_cents' => null,
            'stock' => 5,
        ]);
    }

    /**
     * O admin escreve "normal" e "promocional"; a BD guarda o preco EFETIVO
     * em `price_cents` e o riscado em `compare_at_cents` — invertidos face ao
     * formulario. Este teste prende essa traducao nos dois sentidos.
     */
    public function test_promotional_price_becomes_the_effective_price(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.store', $this->product), [
                ...$this->validPayload(),
                'normal_price' => '24,90',
                'sale_price' => '19,90',
            ]);

        $variant = Variant::query()->firstOrFail();

        $this->assertSame(1990, $variant->price_cents);
        $this->assertSame(2490, $variant->compare_at_cents);

        // E de volta ao formulario, sem o admin ver a troca.
        $this->assertSame(2490, $variant->normalPriceCents());
        $this->assertSame(1990, $variant->salePriceCents());
        $this->assertTrue($variant->isOnSale());
    }

    public function test_removing_the_promotion_restores_the_normal_price(): void
    {
        $variant = Variant::factory()->create([
            'product_id' => $this->product->id,
            'price_cents' => 1990,
            'compare_at_cents' => 2490,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.variantes.update', $variant), [
                ...$this->validPayload(),
                'sku' => $variant->sku,
                'normal_price' => '24.90',
                'sale_price' => '',
            ]);

        $variant->refresh();

        $this->assertSame(2490, $variant->price_cents);
        $this->assertNull($variant->compare_at_cents);
        $this->assertFalse($variant->isOnSale());
    }

    public function test_weight_and_wholesale_price_are_stored(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.store', $this->product), [
                ...$this->validPayload(),
                'wholesale_price' => '12,00',
                'filament_weight_grams' => 45,
            ]);

        $this->assertDatabaseHas('variants', [
            'sku' => 'VASO-PLA-20',
            'wholesale_price_cents' => 1200,
            'filament_weight_grams' => 45,
        ]);
    }

    public function test_a_variant_can_be_given_a_colour(): void
    {
        $color = Color::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.store', $this->product), [
                ...$this->validPayload(),
                'color_id' => $color->id,
            ]);

        $this->assertSame($color->id, Variant::query()->firstOrFail()->color_id);
    }

    /**
     * Cor e material sao eixos independentes: a variante aponta para os dois.
     */
    public function test_a_variant_can_be_given_a_material(): void
    {
        $material = Material::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.store', $this->product), [
                ...$this->validPayload(),
                'material_id' => $material->id,
            ]);

        $this->assertSame($material->id, Variant::query()->firstOrFail()->material_id);
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

    public function test_promotional_price_must_be_below_the_normal_price(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.store', $this->product), [
                ...$this->validPayload(),
                'normal_price' => '19.90',
                'sale_price' => '24.90',
            ])
            ->assertSessionHasErrors('sale_price');

        $this->assertDatabaseCount('variants', 0);
    }

    public function test_wholesale_price_cannot_exceed_the_selling_price(): void
    {
        // Compara contra o promocional quando existe, nao contra o normal:
        // e o promocional que o cliente final paga.
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.store', $this->product), [
                ...$this->validPayload(),
                'normal_price' => '24.90',
                'sale_price' => '19.90',
                'wholesale_price' => '22.00',
            ])
            ->assertSessionHasErrors('wholesale_price');
    }

    /**
     * Duas listas planas e independentes: as cores ativas de um lado, os
     * materiais ativos do outro. Arquivados ficam de fora dos dois.
     */
    public function test_the_create_page_carries_active_colours_and_materials(): void
    {
        Color::factory()->count(2)->create();
        Color::factory()->archived()->create();
        Material::factory()->create(['name' => 'PLA']);
        Material::factory()->archived()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.variantes.create', $this->product))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('colors', 2)
                ->has('materials', 1)
                ->where('materials.0.name', 'PLA'));
    }

    /**
     * Editar uma variante cuja cor foi entretanto arquivada tem de continuar
     * a mostra-la — senao o seletor abria vazio e uma gravacao inocente
     * perdia a cor.
     */
    public function test_editing_keeps_an_archived_colour_in_the_options(): void
    {
        $color = Color::factory()->archived()->create();
        $variant = Variant::factory()->create([
            'product_id' => $this->product->id,
            'color_id' => $color->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.variantes.edit', $variant))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('colors', 1)
                ->where('colors.0.id', $color->id)
                ->where('variant.colorId', $color->id));
    }

    /** A mesma rede, do lado do material. */
    public function test_editing_keeps_an_archived_material_in_the_options(): void
    {
        $material = Material::factory()->archived()->create();
        $variant = Variant::factory()->create([
            'product_id' => $this->product->id,
            'material_id' => $material->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.variantes.edit', $variant))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('materials', 1)
                ->where('materials.0.id', $material->id)
                ->where('variant.materialId', $material->id));
    }

    public function test_destroy_archives_instead_of_deleting(): void
    {
        $variant = Variant::factory()->create(['product_id' => $this->product->id]);
        $modal = route('admin.produtos.index', ['editar' => $this->product->id]);

        // Arquivar dispara-se de dentro do modal, e por isso responde com um
        // `back()` — ao contrario de criar e editar, que vem do formulario da
        // variante e tem de nomear o destino.
        $this->actingAs($this->admin)
            ->from($modal)
            ->delete(route('admin.variantes.destroy', $variant))
            ->assertRedirect($modal);

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
