<?php

namespace Tests\Feature\Admin;

use App\Models\Color;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MaterialIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_state_of_each_material_is_derived_from_stock_and_archiving(): void
    {
        Material::factory()->create(['name' => 'PLA', 'sort_order' => 1]);
        Material::factory()->lowStock()->create(['name' => 'PETG', 'sort_order' => 2]);
        Material::factory()->archived()->create(['name' => 'ABS', 'sort_order' => 3]);

        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/materiais/index')
                ->has('materials', 3)
                ->where('materials.0.state', 'active')
                ->where('materials.1.state', 'low_stock')
                ->where('materials.2.state', 'archived'));
    }

    /**
     * Minimo a zero e "sem alerta", nao "alerta sempre": e o default da coluna,
     * e sem esta regra todos os materiais anteriores a migracao acordavam a
     * amarelo.
     */
    public function test_a_material_without_a_minimum_is_never_low_on_stock(): void
    {
        Material::factory()->create(['spools_in_stock' => 0, 'min_spools' => 0]);

        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('materials.0.state', 'active')
                ->where('stats.belowMinimumCount', 0));
    }

    /**
     * Arquivados fora de todas as metricas: o que ja nao se usa nao se conta
     * como stock nem como falta, e nao pode puxar a media do preco.
     */
    public function test_the_stats_ignore_archived_materials(): void
    {
        Material::factory()->create(['price_per_kg_cents' => 2000, 'spools_in_stock' => 6, 'min_spools' => 3]);
        Material::factory()->lowStock()->create(['price_per_kg_cents' => 3000]);
        Material::factory()->archived()->create(['price_per_kg_cents' => 9900, 'spools_in_stock' => 50]);

        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.activeCount', 2)
                ->where('stats.spoolsTotal', 7)
                ->where('stats.averagePricePerKgCents', 2500)
                ->where('stats.belowMinimumCount', 1));
    }

    /**
     * A media divide pelo numero de materiais — sem linha nenhuma isso e uma
     * divisao por zero, e a listagem quer um numero para formatar.
     */
    public function test_the_average_price_is_zero_without_materials(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.averagePricePerKgCents', 0)
                ->where('stats.activeCount', 0));
    }

    public function test_only_active_colours_become_swatches(): void
    {
        $material = Material::factory()->create();
        Color::factory()->create(['material_id' => $material->id, 'name' => 'Preto', 'sort_order' => 0]);
        Color::factory()->create(['material_id' => $material->id, 'name' => 'Branco', 'is_active' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('materials.0.colors', 1)
                ->where('materials.0.colors.0.name', 'Preto'));
    }

    public function test_the_modal_receives_the_palette_and_the_families(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('palette', 8)
                ->where('palette.0.name', 'Preto')
                ->where('palette.0.hex', '#1A1715')
                ->where('families', Material::FAMILIES));
    }

    public function test_non_admins_cannot_see_the_listing(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.materiais.index'))
            ->assertForbidden();
    }
}
