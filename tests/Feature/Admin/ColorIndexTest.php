<?php

namespace Tests\Feature\Admin;

use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A listagem mostra a camada de cima: uma entrada por NOME de cor, com as
 * linhas dos varios materiais dobradas la dentro.
 */
class ColorIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Material $pla;

    private Material $petg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->pla = Material::factory()->create([
            'name' => 'PLA',
            'price_per_kg_cents' => 2190,
            'sort_order' => 1,
        ]);
        $this->petg = Material::factory()->create([
            'name' => 'PETG',
            'price_per_kg_cents' => 2600,
            'sort_order' => 2,
        ]);
    }

    private function color(Material $material, string $name, ?int $ownPrice = null, bool $active = true): Color
    {
        return Color::factory()->create([
            'material_id' => $material->id,
            'name' => $name,
            'hex_color' => '#1A1715',
            'price_per_kg_cents' => $ownPrice,
            'is_active' => $active,
        ]);
    }

    public function test_the_same_name_in_two_materials_is_one_row(): void
    {
        $this->color($this->pla, 'Preto');
        $this->color($this->petg, 'Preto');
        $this->color($this->pla, 'Bege');

        $this->actingAs($this->admin)
            ->get(route('admin.cores.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/cores/index')
                ->has('colors', 2)
                // Ordenadas por nome, com as arquivadas no fim.
                ->where('colors.0.name', 'Bege')
                ->where('colors.1.name', 'Preto')
                ->has('colors.1.materials', 2));
    }

    /**
     * O preco e um INTERVALO porque cada material tem o seu — e a legenda diz
     * de onde ele vem, senao o intervalo parecia um erro.
     */
    public function test_an_inherited_colour_shows_the_range_of_its_materials(): void
    {
        $this->color($this->pla, 'Preto');
        $this->color($this->petg, 'Preto');

        $this->actingAs($this->admin)
            ->get(route('admin.cores.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('colors.0.minPricePerKgCents', 2190)
                ->where('colors.0.maxPricePerKgCents', 2600)
                ->where('colors.0.priceOrigin', 'inherited')
                ->where('colors.0.ownPricePerKgCents', null));
    }

    public function test_the_same_override_in_every_material_is_a_single_price(): void
    {
        $this->color($this->pla, 'Dourado', ownPrice: 2900);
        $this->color($this->petg, 'Dourado', ownPrice: 2900);

        $this->actingAs($this->admin)
            ->get(route('admin.cores.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('colors.0.priceOrigin', 'own')
                ->where('colors.0.minPricePerKgCents', 2900)
                ->where('colors.0.maxPricePerKgCents', 2900)
                ->where('colors.0.ownPricePerKgCents', 2900));
    }

    /**
     * Override num material e herdado noutro: o caso que o desenho nao previa.
     * O `ownPricePerKgCents` vai a null de proposito — nao ha UM valor a por no
     * campo do modal, e escolher um por conta propria era gravar um preco que
     * ninguem pediu.
     */
    public function test_a_partial_override_is_reported_as_mixed(): void
    {
        $this->color($this->pla, 'Malva', ownPrice: 3000);
        $this->color($this->petg, 'Malva');

        $this->actingAs($this->admin)
            ->get(route('admin.cores.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('colors.0.priceOrigin', 'mixed')
                ->where('colors.0.ownPricePerKgCents', null)
                ->where('stats.ownPriceCount', 1));
    }

    /**
     * As cores nao tem stock; os materiais e que tem. Enquanto houver UM
     * material com bobines, a cor imprime-se.
     */
    public function test_a_colour_is_only_low_on_stock_when_every_material_is(): void
    {
        $empty = Material::factory()->lowStock()->create(['name' => 'TPU 95A', 'sort_order' => 3]);

        $this->color($this->pla, 'Preto');
        $this->color($empty, 'Preto');
        $this->color($empty, 'Malva');

        $this->actingAs($this->admin)
            ->get(route('admin.cores.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('colors.0.name', 'Malva')
                ->where('colors.0.state', 'low_stock')
                ->where('colors.1.name', 'Preto')
                ->where('colors.1.state', 'active'));
    }

    public function test_a_colour_archived_everywhere_shows_no_materials(): void
    {
        $this->color($this->pla, 'Cinza pedra', active: false);
        $this->color($this->petg, 'Cinza pedra', active: false);

        $this->actingAs($this->admin)
            ->get(route('admin.cores.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('colors.0.state', 'archived')
                ->has('colors.0.materials', 0)
                ->where('colors.0.minPricePerKgCents', null)
                ->where('colors.0.priceOrigin', 'none')
                ->where('stats.activeCount', 0)
                ->where('stats.pairsCount', 0));
    }

    public function test_the_stats_count_identities_and_pairs_apart(): void
    {
        $this->color($this->pla, 'Preto');
        $this->color($this->petg, 'Preto');
        $this->color($this->pla, 'Bege');
        $this->color($this->petg, 'Cinza pedra', active: false);

        $variant = Variant::factory()->create([
            'product_id' => Product::factory(),
            'color_id' => Color::query()->where('name', 'Preto')->firstOrFail()->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.cores.index'))
            ->assertInertia(fn (Assert $page) => $page
                // Preto e Bege; "Cinza pedra" esta arquivada.
                ->where('stats.activeCount', 2)
                // Tres linhas `colors` ativas para essas duas cores.
                ->where('stats.pairsCount', 3)
                // So a Bege e que nenhuma variante usa.
                ->where('stats.unusedCount', 1));

        $this->assertNotNull($variant->color_id);
    }

    /**
     * A vista "Por material" sai dos materiais e nao das cores, para um
     * material ainda sem cores continuar a aparecer — e um buraco a preencher,
     * nao uma linha a esconder.
     */
    public function test_a_material_without_colours_still_shows_up(): void
    {
        $this->color($this->pla, 'Preto', ownPrice: 2500);

        $this->actingAs($this->admin)
            ->get(route('admin.cores.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('materials', 2)
                ->where('materials.0.name', 'PLA')
                ->has('materials.0.colors', 1)
                ->where('materials.0.colors.0.ownPricePerKgCents', 2500)
                ->where('materials.1.name', 'PETG')
                ->has('materials.1.colors', 0));
    }

    public function test_non_admins_cannot_see_the_listing(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.cores.index'))
            ->assertForbidden();
    }
}
