<?php

namespace Tests\Feature\Admin;

use App\Models\Material;
use App\Models\PrinterProfile;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class VariantPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        PrinterProfile::factory()->isDefault()->create([
            'name' => 'Bambu Lab A1',
            'hourly_rate_cents' => 50,
        ]);

        $this->material = Material::factory()->create(['price_per_kg_cents' => 1_700]);
    }

    /**
     * A ficha da variante abre com o preco da variante como ela esta gravada —
     * sem parametros no URL. Sem isto, quem abre uma variante ja completa via
     * um painel vazio ate mexer nalgum campo.
     */
    public function test_the_variant_form_shows_the_suggested_prices_on_first_open(): void
    {
        $variant = Variant::factory()->create([
            'material_id' => $this->material->id,
            'filament_weight_grams' => 32,
            'printing_time_minutes' => 90,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.variantes.edit', $variant))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pricing.result.productionCostMicros', 1_647_520)
                ->where('pricing.result.resalePriceCents', 350)
                ->where('pricing.result.retailPriceCents', 600)
                ->where('pricing.usingFallbackRate', false)
            );
    }

    /**
     * O preco por kg vem do MATERIAL da variante: e a bobine que tem preco. Um
     * custo calculado com outro numero nao correspondia a filamento nenhum que
     * exista em stock.
     */
    public function test_the_suggestion_uses_the_price_per_kg_of_the_chosen_material(): void
    {
        $expensive = Material::factory()->create(['price_per_kg_cents' => 3_400]);

        $variant = Variant::factory()->create([
            'material_id' => $expensive->id,
            'filament_weight_grams' => 32,
            'printing_time_minutes' => 90,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.variantes.edit', $variant))
            ->assertInertia(fn (AssertableInertia $page) => $page
                // 32 g x 0,034 EUR/g = 1,088 EUR, o dobro do PLA a 17 EUR/kg.
                ->where('pricing.result.filamentCostMicros', 1_088_000)
            );
    }

    public function test_the_suggestion_uses_the_printer_profile_of_the_variant(): void
    {
        $fast = PrinterProfile::factory()->create(['hourly_rate_cents' => 100]);

        $variant = Variant::factory()->create([
            'material_id' => $this->material->id,
            'filament_weight_grams' => 32,
            'printing_time_minutes' => 90,
            'printer_profile_id' => $fast->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.variantes.edit', $variant))
            ->assertInertia(fn (AssertableInertia $page) => $page
                // 1,5 h x 1,00 EUR/h, o dobro da A1.
                ->where('pricing.result.machineCostMicros', 1_500_000)
                ->where('pricing.hourlyRateCents', 100)
            );
    }

    /**
     * Sem tempo de impressao nao ha preco sugerido nenhum, e esta certo: e a
     * regra fundamental desta versao. As variantes que ja existem entram todas
     * neste caso ate alguem lhes preencher o tempo.
     */
    public function test_a_variant_without_a_print_time_gets_no_suggestion(): void
    {
        $variant = Variant::factory()->create([
            'material_id' => $this->material->id,
            'filament_weight_grams' => 32,
            'printing_time_minutes' => null,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.variantes.edit', $variant))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pricing.result', null));
    }

    /**
     * O painel recarrega-se sozinho com `only: ['pricing']` enquanto o admin
     * escreve — sem gravar nada e sem perder o resto do formulario.
     */
    public function test_the_preview_recalculates_from_the_form_fields(): void
    {
        $variant = Variant::factory()->create([
            'material_id' => $this->material->id,
            'filament_weight_grams' => 32,
            'printing_time_minutes' => 90,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.variantes.edit', $variant).'?'.http_build_query([
                'weight_grams' => 32,
                'hours' => 0,
                'minutes' => 30,
                'material_id' => $this->material->id,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Metade do tempo: o preco de revenda desce de 3,50 para 2,50.
                ->where('pricing.result.productionCostMicros', 1_107_520)
                ->where('pricing.result.resalePriceCents', 250)
            );
    }

    public function test_the_print_time_and_extra_cost_are_stored(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.store', $product), [
                'sku' => 'TEST-0001',
                'material_id' => $this->material->id,
                'normal_price' => '6.00',
                'wholesale_price' => '3.50',
                'filament_weight_grams' => 32,
                'printing_time_minutes' => 90,
                'extra_cost' => '0,65',
                'stock' => 0,
                'low_stock_threshold' => 3,
                'is_default' => false,
                'active' => true,
            ])
            ->assertRedirect(route('admin.produtos.index', ['editar' => $product->id]));

        $this->assertDatabaseHas('variants', [
            'sku' => 'TEST-0001',
            'printing_time_minutes' => 90,
            'extra_cost_cents' => 65,
        ]);
    }

    /**
     * Um tempo de impressao impossivel e engano de unidade, nao uma peca.
     */
    public function test_an_absurd_print_time_is_rejected(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.store', $product), [
                'sku' => 'TEST-0002',
                'normal_price' => '6.00',
                'filament_weight_grams' => 32,
                'printing_time_minutes' => 999_999,
                'stock' => 0,
                'low_stock_threshold' => 3,
            ])
            ->assertSessionHasErrors('printing_time_minutes');
    }
}
