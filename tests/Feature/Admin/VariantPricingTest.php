<?php

namespace Tests\Feature\Admin;

use App\Models\Material;
use App\Models\PrinterProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * O painel de custo da ficha de variante.
 *
 * A ficha vive dentro do modal do produto, e por isso e a LISTAGEM de produtos
 * que serve a prop `pricing` — calculada pelo mesmo motor que calcula o preco
 * gravado, a partir dos campos que o formulario manda no URL
 * (`only: ['pricing']`). E a mesma prop que a calculadora usa; o que muda e so
 * a pagina que a hospeda.
 */
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
            'hourly_rate_cents' => 20,
        ]);

        $this->material = Material::factory()->create(['price_per_kg_cents' => 1_700]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function preview(array $fields): TestResponse
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.produtos.index', $fields));
    }

    /**
     * O caso base, com o produto aberto no modal ao lado dos campos do calculo
     * — que e exatamente o URL que o formulario produz enquanto se escreve.
     */
    public function test_the_listing_prices_the_fields_the_form_sends(): void
    {
        $product = Product::factory()->create();

        $this->preview([
            'editar' => $product->id,
            'weight_grams' => 45,
            'hours' => 2,
            'minutes' => 30,
            'material_id' => $this->material->id,
        ])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pricing.result.productionCostMicros', 1_478_250)
            ->where('pricing.result.resalePriceCents', 300)
            ->where('pricing.result.retailPriceCents', 550)
            ->where('pricing.usingFallbackRate', false)
        );
    }

    /**
     * Mexer num campo mexe no preco. E a mesma resposta com outros parametros —
     * o painel nao guarda estado nenhum entre pedidos.
     *
     * Metade do tempo do caso base (1h15 contra 2h30), e nao uma peca mais
     * pequena: uma peca pequena de mais cai no chao de 1,50 EUR e o teste
     * deixava de conseguir provar que o tempo mexeu no preco.
     */
    public function test_halving_the_print_time_halves_the_machine_share(): void
    {
        $this->preview([
            'weight_grams' => 45,
            'hours' => 1,
            'minutes' => 15,
            'material_id' => $this->material->id,
        ])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pricing.result.machineCostMicros', 250_000)
            ->where('pricing.result.productionCostMicros', 1_215_750)
            ->where('pricing.result.resalePriceCents', 250)
        );
    }

    /**
     * O preco por kg vem do MATERIAL: e a bobine que tem preco. Um custo
     * calculado com outro numero nao correspondia a filamento nenhum que exista
     * em stock.
     */
    public function test_the_suggestion_uses_the_price_per_kg_of_the_chosen_material(): void
    {
        $expensive = Material::factory()->create(['price_per_kg_cents' => 3_400]);

        $this->preview([
            'weight_grams' => 45,
            'hours' => 2,
            'minutes' => 30,
            'material_id' => $expensive->id,
        ])->assertInertia(fn (AssertableInertia $page) => $page
            // 45 g x 0,034 EUR/g = 1,53 EUR, o dobro do PLA a 17 EUR/kg.
            ->where('pricing.result.filamentCostMicros', 1_530_000)
        );
    }

    public function test_the_suggestion_uses_the_chosen_printer_profile(): void
    {
        $expensive = PrinterProfile::factory()->create(['hourly_rate_cents' => 40]);

        $this->preview([
            'weight_grams' => 45,
            'hours' => 2,
            'minutes' => 30,
            'material_id' => $this->material->id,
            'printer_profile_id' => $expensive->id,
        ])->assertInertia(fn (AssertableInertia $page) => $page
            // 2,5 h x 0,40 EUR/h, o dobro da A1.
            ->where('pricing.result.machineCostMicros', 1_000_000)
            ->where('pricing.hourlyRateCents', 40)
        );
    }

    /**
     * Sem tempo de impressao nao ha preco sugerido nenhum, e esta certo: e a
     * regra fundamental desta versao. E tambem o estado em que a ficha de uma
     * variante nova abre — sem campos no URL, a listagem devolve o mesmo.
     */
    public function test_without_a_print_time_there_is_no_suggestion(): void
    {
        $this->preview([
            'weight_grams' => 32,
            'material_id' => $this->material->id,
        ])->assertInertia(fn (AssertableInertia $page) => $page->where('pricing.result', null));
    }

    public function test_the_bare_listing_has_nothing_to_suggest(): void
    {
        $this->preview([])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pricing.result', null));
    }

    /**
     * A fronteira com a calculadora, escrita como teste.
     *
     * A calculadora recusa mostrar preco sem filamento escolhido, mas essa regra
     * e DELA e vive no PricingCalculatorController. Aqui o material e opcional:
     * uma variante sem material ainda tem tempo de maquina e manuseamento a
     * contar, e o painel tem de continuar a mostra-los.
     *
     * Se alguem "simplificar" a regra para dentro do PricingPreviewRequest — que
     * as duas paginas partilham — este teste cai. E o aviso.
     */
    public function test_without_a_material_there_is_still_a_suggestion(): void
    {
        $this->preview([
            'weight_grams' => 45,
            'hours' => 2,
            'minutes' => 30,
        ])->assertInertia(fn (AssertableInertia $page) => $page
            // Sem bobine nao ha plastico a pagar, mas a maquina andou:
            // 2,5 h x 0,20 EUR/h.
            ->where('pricing.result.filamentCostMicros', 0)
            ->where('pricing.result.machineCostMicros', 500_000)
        );
    }

    public function test_the_print_time_and_extra_cost_are_stored(): void
    {
        $product = Product::factory()->create();
        $modal = route('admin.produtos.index', ['editar' => $product->id]);

        $this->actingAs($this->admin)
            ->from($modal)
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
            ->assertRedirect($modal);

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
