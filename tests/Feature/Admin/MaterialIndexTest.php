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

    /**
     * Instalacao nova: sem uma unica cor as chips seriam zero, e o modal exige
     * pelo menos uma para criar o material. Os presets sao a rede.
     */
    public function test_the_modal_falls_back_to_the_presets_without_colours(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('colorOptions', 8)
                ->where('colorOptions.0.name', 'Preto')
                ->where('colorOptions.0.hex', '#1A1715')
                ->where('families', Material::FAMILIES));
    }

    /**
     * O que o modal oferece sao as cores que o admin criou, agrupadas pelo nome
     * — a mesma camada que /admin/cores mostra.
     */
    public function test_the_modal_offers_the_colours_already_created(): void
    {
        $pla = Material::factory()->create(['name' => 'PLA']);
        $petg = Material::factory()->create(['name' => 'PETG']);

        Color::factory()->create(['material_id' => $pla->id, 'name' => 'Preto', 'hex_color' => '#1A1715']);
        Color::factory()->create(['material_id' => $petg->id, 'name' => 'Preto', 'hex_color' => '#1A1715']);
        Color::factory()->create(['material_id' => $pla->id, 'name' => 'Bege', 'hex_color' => '#C6A77B']);

        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page
                // "Preto" existe em dois materiais e continua a ser uma entrada.
                ->has('colorOptions', 2)
                ->where('colorOptions.0.name', 'Bege')
                ->where('colorOptions.0.hex', '#C6A77B')
                ->where('colorOptions.1.name', 'Preto'));
    }

    /**
     * O fallback e exclusivo, nao uma uniao: basta uma cor a serio para os
     * presets sairem de cena.
     */
    public function test_the_presets_disappear_once_there_is_a_colour(): void
    {
        Color::factory()->create(['name' => 'Rosa neon']);

        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('colorOptions', 1)
                ->where('colorOptions.0.name', 'Rosa neon'));
    }

    /**
     * Arquivada em todos os materiais e o que /admin/cores mostra como
     * arquivada. Oferece-la no modal era ressuscita-la pela porta das traseiras.
     */
    public function test_a_colour_archived_everywhere_is_not_offered(): void
    {
        Color::factory()->archived()->create(['name' => 'Cinza pedra']);
        Color::factory()->archived()->create(['name' => 'Cinza pedra']);
        Color::factory()->create(['name' => 'Preto']);

        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('colorOptions', 1)
                ->where('colorOptions.0.name', 'Preto'));
    }

    /**
     * Ja arquivada so nalguns materiais, o grupo continua vivo — o `is_active`
     * e por par cor×material.
     */
    public function test_a_colour_archived_in_one_material_is_still_offered(): void
    {
        Color::factory()->create(['name' => 'Preto']);
        Color::factory()->archived()->create(['name' => 'Preto']);

        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page->has('colorOptions', 1));
    }

    /**
     * Divergencia deliberada face ao ColorGroups, que filtra materiais
     * arquivados: aqui escolhem-se NOMES para um material que ainda nao existe,
     * e /admin/cores tambem mostra esta cor como activa.
     */
    public function test_a_colour_of_an_archived_material_is_still_offered(): void
    {
        $material = Material::factory()->archived()->create();

        Color::factory()->create(['material_id' => $material->id, 'name' => 'Preto']);

        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page->has('colorOptions', 1));
    }

    /**
     * O indice unico e por material, por isso o mesmo nome pode ter hex
     * diferentes. Ganha o da linha mais antiga: e o unico criterio que nao muda
     * quando se acrescenta ou arquiva um material.
     */
    public function test_the_chip_uses_the_hex_of_the_oldest_row(): void
    {
        Color::factory()->create(['name' => 'Terracota', 'hex_color' => '#B0684A']);
        Color::factory()->create(['name' => 'Terracota', 'hex_color' => '#B06A4C']);

        $this->actingAs($this->admin)
            ->get(route('admin.materiais.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('colorOptions.0.hex', '#B0684A'));
    }

    public function test_non_admins_cannot_see_the_listing(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.materiais.index'))
            ->assertForbidden();
    }
}
