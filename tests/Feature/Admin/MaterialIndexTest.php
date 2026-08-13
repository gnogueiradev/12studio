<?php

namespace Tests\Feature\Admin;

use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
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

    /**
     * O numero acionavel que substituiu os swatches de cor: quantas variantes
     * dependem desta bobine, e portanto porque e que ela nao se apaga.
     */
    public function test_each_material_carries_how_many_variants_depend_on_it(): void
    {
        $pla = Material::factory()->create(['name' => 'PLA', 'sort_order' => 1]);
        Material::factory()->create(['name' => 'PETG', 'sort_order' => 2]);

        $product = Product::factory()->create();
        Variant::factory()->count(2)->create([
            'product_id' => $product->id,
            'material_id' => $pla->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('materials.0.name', 'PLA')
                ->where('materials.0.variantsCount', 2)
                ->where('materials.1.name', 'PETG')
                ->where('materials.1.variantsCount', 0));
    }

    /**
     * As cores sairam desta pagina com o desacoplamento — a prop `colorOptions`
     * deixou de existir. As familias ficam.
     */
    public function test_the_listing_carries_the_families_and_no_colours(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('families', Material::FAMILIES)
                ->missing('colorOptions'));
    }

    public function test_non_admins_cannot_see_the_listing(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.materiais.index'))
            ->assertForbidden();
    }
}
