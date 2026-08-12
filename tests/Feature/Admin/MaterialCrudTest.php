<?php

namespace Tests\Feature\Admin;

use App\Models\Color;
use App\Models\Material;
use App\Models\User;
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
     * Regra global de eliminacao logica: as cores e as variantes que ja usam
     * o material tem de sobreviver ao arquivo.
     */
    public function test_destroy_archives_and_keeps_the_colours(): void
    {
        $material = Material::factory()->create();
        $color = Color::factory()->create(['material_id' => $material->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.materiais.destroy', $material))
            ->assertRedirect(route('admin.materiais.index'));

        $this->assertDatabaseHas('materials', ['id' => $material->id, 'active' => false]);
        $this->assertDatabaseHas('colors', ['id' => $color->id, 'is_active' => true]);
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
     * As chips do modal sao presets: o material e as suas primeiras cores
     * nascem juntos, senao ficava um material que nao gera variante nenhuma.
     */
    public function test_store_creates_the_selected_palette_colours(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materiais.store'), [
                ...$this->validPayload(),
                'colors' => ['Preto', 'Dourado'],
            ])
            ->assertSessionHasNoErrors();

        $material = Material::query()->where('name', 'PLA')->sole();

        $this->assertSame(2, $material->colors()->count());
        $this->assertDatabaseHas('colors', [
            'material_id' => $material->id,
            'name' => 'Preto',
            'hex_color' => '#1A1715',
            // Sem override: a cor herda o preco/kg que o modal acabou de pedir.
            'price_per_kg_cents' => null,
        ]);
    }

    public function test_store_rejects_a_colour_outside_the_palette(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materiais.store'), [
                ...$this->validPayload(),
                'colors' => ['Cor-de-burro-quando-foge'],
            ])
            ->assertSessionHasErrors('colors.0');

        $this->assertDatabaseMissing('materials', ['name' => 'PLA']);
    }

    /**
     * A paleta e um atalho de criacao. Um material que ja existe tem cores
     * proprias, com precos e imagens tratados em /admin/cores — gravar o
     * formulario de edicao nao lhes pode mexer.
     */
    public function test_update_ignores_colours(): void
    {
        $material = Material::factory()->create(['name' => 'PLA']);

        $this->actingAs($this->admin)
            ->patch(route('admin.materiais.update', $material), [
                ...$this->validPayload(),
                'colors' => ['Preto', 'Branco'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $material->colors()->count());
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
