<?php

namespace Tests\Feature\Admin;

use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'PLA',
            'family' => 'PLA',
            'supplier' => 'Prusament',
            'price_per_kg' => '21.90',
            'spools_in_stock' => 9,
            'min_spools' => 4,
            'active' => true,
            'sort_order' => 0,
        ];
    }

    public function test_store_converts_euros_to_cents(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materiais.store'), [
                ...$this->validPayload(),
                'price_per_kg' => '21,90',
            ])
            ->assertRedirect(route('admin.materiais.index'));

        $this->assertDatabaseHas('materials', [
            'name' => 'PLA',
            'price_per_kg_cents' => 2190,
        ]);
    }

    public function test_duplicate_name_is_rejected(): void
    {
        Material::factory()->create(['name' => 'PLA']);

        $this->actingAs($this->admin)
            ->post(route('admin.materiais.store'), $this->validPayload())
            ->assertSessionHasErrors('name');
    }

    public function test_a_material_can_keep_its_own_name_when_edited(): void
    {
        $material = Material::factory()->create(['name' => 'PLA']);

        $this->actingAs($this->admin)
            ->patch(route('admin.materiais.update', $material), [
                ...$this->validPayload(),
                'price_per_kg' => '24.50',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2450, $material->refresh()->price_per_kg_cents);
    }

    /**
     * Regra global de eliminacao logica: as variantes que ja usam o material
     * tem de sobreviver ao arquivo.
     */
    public function test_destroy_archives_and_keeps_the_variants(): void
    {
        $material = Material::factory()->create();
        $variant = Variant::factory()->create([
            'product_id' => Product::factory(),
            'material_id' => $material->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.materiais.destroy', $material))
            ->assertRedirect(route('admin.materiais.index'));

        $this->assertDatabaseHas('materials', ['id' => $material->id, 'active' => false]);
        $this->assertSame($material->id, $variant->refresh()->material_id);
    }

    public function test_restore_makes_an_archived_material_selectable_again(): void
    {
        $material = Material::factory()->archived()->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.materiais.restaurar', $material))
            ->assertRedirect();

        $this->assertTrue($material->refresh()->active);
    }

    /**
     * As cores sairam deste formulario com o desacoplamento: uma cor nao
     * pertence a um material, e cria-se em /admin/cores. Manda `colors` de
     * proposito — e este teste que apanha o dia em que a chave voltar a ser
     * aceite sem ninguem ter pedido.
     */
    public function test_store_ignores_a_colours_key_smuggled_into_the_payload(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materiais.store'), [
                ...$this->validPayload(),
                'colors' => [
                    ['name' => 'Preto', 'hex' => '#1A1715'],
                    ['name' => 'Dourado', 'hex' => '#D9A84E'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('materials', ['name' => 'PLA']);
        $this->assertDatabaseCount('colors', 0);
    }

    public function test_update_ignores_a_colours_key_smuggled_into_the_payload(): void
    {
        $material = Material::factory()->create(['name' => 'PLA']);

        $this->actingAs($this->admin)
            ->patch(route('admin.materiais.update', $material), [
                ...$this->validPayload(),
                'colors' => [['name' => 'Preto', 'hex' => '#1A1715']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('colors', 0);
    }

    public function test_non_admins_cannot_touch_materials(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.materiais.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('materials', ['name' => 'PLA']);
    }
}
