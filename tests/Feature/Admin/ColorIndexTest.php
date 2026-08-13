<?php

namespace Tests\Feature\Admin;

use App\Models\Color;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A listagem e uma lista plana: uma linha por cor. Sem materiais e sem precos —
 * a cor deixou de ter os dois.
 */
class ColorIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function color(string $name, int $sortOrder = 0, bool $active = true): Color
    {
        return Color::factory()->create([
            'name' => $name,
            'hex_color' => '#1A1715',
            'sort_order' => $sortOrder,
            'is_active' => $active,
        ]);
    }

    public function test_the_listing_orders_by_sort_order_then_name(): void
    {
        $this->color('Preto', sortOrder: 1);
        $this->color('Bege', sortOrder: 0);
        $this->color('Azul pedra', sortOrder: 1);

        $this->actingAs($this->admin)
            ->get(route('admin.cores.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/cores/index')
                ->has('colors', 3)
                ->where('colors.0.name', 'Bege')
                ->where('colors.1.name', 'Azul pedra')
                ->where('colors.2.name', 'Preto'));
    }

    /**
     * Arquivadas no fim, independentemente da ordem: sao as unicas que nao
     * pedem nada a ninguem.
     */
    public function test_an_archived_colour_is_listed_last(): void
    {
        $this->color('Cinza pedra', sortOrder: 0, active: false);
        $this->color('Preto', sortOrder: 9);

        $this->actingAs($this->admin)
            ->get(route('admin.cores.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('colors.0.name', 'Preto')
                ->where('colors.0.state', 'active')
                ->where('colors.1.name', 'Cinza pedra')
                ->where('colors.1.state', 'archived'));
    }

    public function test_the_stats_count_active_archived_and_unused(): void
    {
        $preto = $this->color('Preto');
        $this->color('Bege');
        $this->color('Cinza pedra', active: false);

        $variant = Variant::factory()->create([
            'product_id' => Product::factory(),
            'color_id' => $preto->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.cores.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.activeCount', 2)
                ->where('stats.archivedCount', 1)
                // So a Bege e que nenhuma variante usa.
                ->where('stats.unusedCount', 1));

        $this->assertNotNull($variant->color_id);
    }

    /**
     * A contagem soma TODAS as variantes, e e ela que justifica nunca se apagar
     * uma cor.
     */
    public function test_each_colour_carries_how_many_variants_use_it(): void
    {
        $preto = $this->color('Preto');
        $product = Product::factory()->create();

        Variant::factory()->count(2)->create([
            'product_id' => $product->id,
            'color_id' => $preto->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.cores.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('colors.0.variantsCount', 2));
    }

    /**
     * Os presets do FilamentPalette continuam a viajar para as chips do modal —
     * sao o atalho para escolher um tom, nao o catalogo.
     */
    public function test_the_palette_presets_reach_the_modal(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.cores.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('palette', 8)
                ->where('palette.0.name', 'Preto'));
    }

    public function test_non_admins_cannot_see_the_listing(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.cores.index'))
            ->assertForbidden();
    }
}
