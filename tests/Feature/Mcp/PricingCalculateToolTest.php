<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Read\PricingCalculateTool;
use App\Mcp\Tools\Read\PricingPreviewTool;
use App\Mcp\Tools\Read\PrintersListTool;
use App\Models\Material;
use App\Models\McpActivity;
use App\Models\PrinterProfile;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\PricingSettings;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\Concerns\PricesWithoutVat;
use Tests\TestCase;

/**
 * A calculadora livre pelo MCP: o que a pagina /admin/calculadora faz, sem
 * variante nenhuma por tras.
 */
class PricingCalculateToolTest extends TestCase
{
    use PricesWithoutVat;
    use RefreshDatabase;

    private User $admin;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->material = Material::factory()->create(['name' => 'PETG Preto', 'price_per_kg_cents' => 2000]);
    }

    private function server(): PendingTestResponse
    {
        return StudioServer::actingAs($this->admin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function piece(array $overrides = []): array
    {
        return [
            'material_id' => $this->material->id,
            'weight_grams' => 50,
            'hours' => 2,
            'minutes' => 0,
            ...$overrides,
        ];
    }

    public function test_it_prices_a_piece_that_is_no_variant_at_all(): void
    {
        $this->server()->tool(PricingCalculateTool::class, $this->piece())
            ->assertOk()
            ->assertSee('PETG Preto')
            ->assertSee('retail_price')
            ->assertSee('production_total')
            ->assertSee('"vat_percent":23')
            ->assertSee('"print_time_minutes":120');

        // O ponto todo: nao foi preciso criar nada para fazer a conta.
        $this->assertSame(0, Variant::query()->count());
        $this->assertSame(0, Product::query()->count());
    }

    /**
     * Sem quote, a calculadora livre e uma peca de catalogo: a MESMA conta da
     * variante, precos incluidos. Com quote=true e um orcamento: o custo e o
     * mesmo (trabalho ativo acima do minimo de 20 min, sem placas nem trocas),
     * mas os precos podem subir pelo lucro minimo do trabalho.
     */
    public function test_it_costs_the_same_as_the_variant_preview(): void
    {
        $printer = PrinterProfile::factory()->isDefault()->create();
        // A calculadora solta usa o IVA da loja; a variante, o do produto.
        $product = Product::factory()->create(['vat_rate' => 23]);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'material_id' => $this->material->id,
            'printer_profile_id' => $printer->id,
            'filament_weight_grams' => 80,
            'printing_time_minutes' => 95,
            'packaging_cost_cents' => 35,
            'components_cost_cents' => 120,
            'active_labor_minutes' => 20,
        ]);

        $saved = $this->structured($this->server()->tool(PricingPreviewTool::class, ['variant_id' => $variant->id]));
        $same = [
            'weight_grams' => 80,
            'hours' => 1,
            'minutes' => 35,
            'packaging_cost' => '0,35',
            'components_cost' => '1.20',
            'active_labor_minutes' => 20,
        ];
        $catalog = $this->structured($this->server()->tool(PricingCalculateTool::class, $this->piece($same)));
        $free = $this->structured($this->server()->tool(PricingCalculateTool::class, $this->piece([...$same, 'quote' => true])));

        $this->assertFalse($catalog['inputs']['quote']);
        $this->assertSame($saved['costs'], $catalog['costs']);
        $this->assertSame($saved['suggested'], $catalog['suggested']);

        $this->assertTrue($free['inputs']['quote']);
        $this->assertSame($saved['costs'], $free['costs']);
        $this->assertSame($saved['labor']['minutes'], $free['labor']['minutes']);

        // A variante nunca usa o lucro minimo do trabalho...
        foreach ($saved['suggested']['tiers'] as $tier) {
            $this->assertNotSame('min_job', $tier['rule']);
        }

        // ...e por isso o orcamento nunca sai mais barato do que ela.
        foreach (['minimum', 'recommended', 'premium'] as $name) {
            $this->assertGreaterThanOrEqual(
                $saved['suggested']['tiers'][$name]['price']['cents'],
                $free['suggested']['tiers'][$name]['price']['cents'],
            );
        }
    }

    public function test_the_quote_inputs_reach_the_labor(): void
    {
        $response = $this->structured($this->server()->tool(PricingCalculateTool::class, $this->piece([
            'quote' => true,
            'plates' => 3,
            'manual_color_changes' => 2,
            'design_minutes' => 30,
        ])));

        $this->assertSame(3, $response['inputs']['plates']);
        $this->assertSame(2, $response['inputs']['manual_color_changes']);
        $this->assertSame(30, $response['inputs']['design_minutes']);

        // 5 de base + 15 para o minimo de 20 + 2 placas a mais x 5 + 2 trocas x 5.
        $this->assertSame(
            ['base' => 5, 'quote_minimum' => 15, 'plates' => 10, 'color_changes' => 10, 'total' => 40],
            $response['labor']['minutes'],
        );
        $this->assertSame(40, $response['inputs']['labor_minutes']);
        $this->assertSame(30, $response['labor']['design_minutes']);
        // 30 min a 15 EUR/h = 7,50 EUR, so no Premium.
        $this->assertSame(750, $response['labor']['design_cost_per_unit']['cents']);
        $this->assertSame(750, $response['suggested']['tiers']['premium']['design_cost']['cents']);
        $this->assertSame(0, $response['suggested']['tiers']['recommended']['design_cost']['cents']);
    }

    public function test_without_quote_the_quote_inputs_are_ignored_and_it_says_so(): void
    {
        $response = $this->structured($this->server()->tool(PricingCalculateTool::class, $this->piece([
            'plates' => 3,
            'manual_color_changes' => 2,
            'design_minutes' => 30,
        ])));

        $this->assertFalse($response['inputs']['quote']);
        $this->assertSame(1, $response['inputs']['plates']);
        $this->assertSame(0, $response['inputs']['manual_color_changes']);
        $this->assertSame(0, $response['inputs']['design_minutes']);
        $this->assertSame(
            ['base' => 5, 'quote_minimum' => 0, 'plates' => 0, 'color_changes' => 0, 'total' => 5],
            $response['labor']['minutes'],
        );
        $this->assertSame(0, $response['suggested']['tiers']['premium']['design_cost']['cents']);
        $this->assertSame(['quote_inputs_ignored'], array_column($response['warnings'], 'code'));

        foreach ($response['suggested']['tiers'] as $tier) {
            $this->assertNotSame('min_job', $tier['rule']);
        }
    }

    public function test_a_chosen_price_comes_back_evaluated(): void
    {
        $pla = Material::factory()->create(['name' => 'PLA Mate', 'price_per_kg_cents' => 1_699]);
        app(SettingService::class)->set(PricingSettings::KEY_LABOR_RATE_MICROS_PER_HOUR, 2_000_000);

        $response = $this->structured($this->server()->tool(PricingCalculateTool::class, [
            'material_id' => $pla->id,
            'weight_grams' => 30,
            'hours' => 2,
            'minutes' => 20,
            'price' => '4,99',
        ]));
        $evaluation = $response['evaluation'];

        $this->assertSame(499, $evaluation['price']['cents']);
        $this->assertSame('4,99 €', $evaluation['price']['eur']);
        $this->assertEqualsWithDelta(310, $evaluation['profit']['cents'], 3, '~3,10');
        $this->assertEqualsWithDelta(133, $evaluation['profit_per_print_hour']['cents'], 2, '~1,33/h');
        $this->assertSame('below_minimum', $evaluation['position']);
        $this->assertTrue($evaluation['below_minimum']);
        $this->assertSame('price_below_minimum', $evaluation['warnings'][0]['code']);
        // So informa: os niveis sao os mesmos que sem preco.
        $this->assertSame(549, $response['suggested']['tiers']['minimum']['price']['cents']);

        $without = $this->structured($this->server()->tool(PricingCalculateTool::class, [
            'material_id' => $pla->id,
            'weight_grams' => 30,
            'hours' => 2,
            'minutes' => 20,
        ]));
        $this->assertNull($without['evaluation']);
        $this->assertSame($without['suggested'], $response['suggested']);
    }

    public function test_a_price_that_is_not_a_number_is_refused(): void
    {
        $this->server()->tool(PricingCalculateTool::class, $this->piece(['price' => 'barato']))
            ->assertHasErrors()
            ->assertSee('Dados inválidos');

        // Zero e "nao avaliar", nao um erro.
        $this->assertNull($this->structured($this->server()->tool(PricingCalculateTool::class, $this->piece(['price' => '0'])))['evaluation']);
    }

    public function test_it_returns_three_tiers_and_keeps_the_old_fields(): void
    {
        $response = $this->structured($this->server()->tool(PricingCalculateTool::class, $this->piece()));
        $tiers = $response['suggested']['tiers'];

        $this->assertSame(['minimum', 'recommended', 'premium'], array_keys($tiers));

        foreach ($tiers as $tier) {
            foreach (['price', 'price_ex_vat', 'vat', 'profit', 'profit_total', 'margin_percent', 'profit_per_print_hour', 'earnings_per_print_hour', 'rule'] as $field) {
                $this->assertArrayHasKey($field, $tier);
            }
            $this->assertContains($tier['rule'], ['margin', 'per_hour', 'min_job']);
            $this->assertSame(49, $tier['price']['cents'] % 50, 'termina em ,49 ou ,99');
        }

        $this->assertLessThan($tiers['recommended']['price']['cents'], $tiers['minimum']['price']['cents']);
        $this->assertLessThan($tiers['premium']['price']['cents'], $tiers['recommended']['price']['cents']);

        // Compatibilidade: os campos de sempre, com o Recomendado.
        $this->assertSame($tiers['recommended']['price'], $response['suggested']['retail_price']);
        $this->assertSame($tiers['recommended']['price_ex_vat'], $response['suggested']['retail_price_ex_vat']);
        $this->assertArrayHasKey('wholesale_price', $response['suggested']);
        $this->assertArrayHasKey('wholesale_price_ex_vat', $response['suggested']);
        $this->assertSame([], $response['warnings']);
    }

    /**
     * O lucro minimo do trabalho divide-se pelas pecas. Para o ver ganhar numa
     * mesa de 20, sobe-se o do Recomendado para 200 EUR: 10 EUR por peca.
     */
    public function test_a_batch_of_twenty_splits_the_minimum_job_profit(): void
    {
        $this->pricesWithoutVat();
        app(SettingService::class)->set(PricingSettings::TIER_KEYS['recommended']['min_job'], 20_000);

        $response = $this->structured($this->server()->tool(PricingCalculateTool::class, $this->piece([
            'quote' => true,
            'mode' => 'batch',
            'quantity' => 20,
            'weight_grams' => 200,
            'hours' => 2,
        ])));
        $recommended = $response['suggested']['tiers']['recommended'];

        $this->assertSame('min_job', $recommended['rule']);
        // Sem IVA: preco = custo + 10,00 arredondado ate ,49/,99 — o lucro por
        // peca fica entre 10,00 e 10,49, e nao nos 200 EUR do trabalho.
        $this->assertGreaterThanOrEqual(1_000, $recommended['profit']['cents']);
        $this->assertLessThan(1_050, $recommended['profit']['cents']);
        $this->assertGreaterThanOrEqual(20_000, $recommended['profit_total']['cents']);
    }

    public function test_a_slicer_filament_cost_far_from_ours_is_flagged(): void
    {
        $pla = Material::factory()->create(['name' => 'PLA Mate', 'price_per_kg_cents' => 1_699]);

        // 66 g a 16,99 EUR/kg = 1,12 EUR com IVA; o Bambu Studio disse 1,32.
        $this->server()->tool(PricingCalculateTool::class, [
            'material_id' => $pla->id,
            'weight_grams' => 66,
            'hours' => 4,
            'minutes' => 37,
            'reference_filament_cost' => '1,32',
        ])
            ->assertOk()
            ->assertSee('filament_reference_mismatch');

        $this->server()->tool(PricingCalculateTool::class, [
            'material_id' => $pla->id,
            'weight_grams' => 66,
            'hours' => 4,
            'minutes' => 37,
            'reference_filament_cost' => '1,20',
        ])
            ->assertOk()
            ->assertDontSee('filament_reference_mismatch');
    }

    public function test_the_filament_cost_comes_from_the_material(): void
    {
        $this->pricesWithoutVat();

        // 50 g a 20,00 EUR/kg = 1,00 EUR de plastico.
        $this->server()->tool(PricingCalculateTool::class, $this->piece())
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('costs.filament.cents', 100)
                ->where('inputs.material.price_per_kg.cents', 2000)
                ->where('suggested.vat_percent', 0)
                ->etc());
    }

    public function test_batch_mode_spreads_the_plate_over_the_quantity(): void
    {
        $this->pricesWithoutVat();

        // A mesa toda leva 500 g: dez pecas, 50 g de plastico cada.
        $this->server()->tool(PricingCalculateTool::class, $this->piece([
            'mode' => 'batch',
            'quantity' => 10,
            'weight_grams' => 500,
            'hours' => 20,
        ]))
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('inputs.mode', 'batch')
                ->where('inputs.quantity', 10)
                ->where('costs.filament.cents', 100)
                ->where('job.quantity', 10)
                ->etc());
    }

    public function test_job_totals_are_the_unit_times_the_quantity(): void
    {
        $response = $this->structured($this->server()->tool(PricingCalculateTool::class, $this->piece(['quantity' => 3])));

        $this->assertSame(3, $response['job']['quantity']);
        $this->assertSame(
            $response['suggested']['retail_price']['cents'] * 3,
            $response['job']['retail_total']['cents'],
        );
    }

    public function test_it_needs_a_filament(): void
    {
        $this->server()->tool(PricingCalculateTool::class, ['weight_grams' => 50, 'hours' => 2])
            ->assertHasErrors()
            ->assertSee('material_id')
            ->assertDontSee('retail_price');
    }

    public function test_it_needs_weight_and_time(): void
    {
        $this->server()->tool(PricingCalculateTool::class, $this->piece(['hours' => 0, 'minutes' => 0]))
            ->assertHasErrors()
            ->assertSee('tempo de impressão');

        $this->server()->tool(PricingCalculateTool::class, $this->piece(['weight_grams' => 0]))
            ->assertHasErrors();
    }

    public function test_it_validates_with_the_rules_of_the_backoffice_form(): void
    {
        // 75 minutos nao existem: ou e 1h15, ou e engano.
        $this->server()->tool(PricingCalculateTool::class, $this->piece(['minutes' => 75]))
            ->assertHasErrors()
            ->assertSee('Dados inválidos');

        $this->server()->tool(PricingCalculateTool::class, $this->piece(['material_id' => $this->material->id + 100]))
            ->assertHasErrors()
            ->assertSee('Dados inválidos');
    }

    public function test_it_uses_the_chosen_printer(): void
    {
        PrinterProfile::factory()->isDefault()->create(['name' => 'Predefinida']);
        $other = PrinterProfile::factory()->create(['name' => 'Outra']);

        $this->server()->tool(PricingCalculateTool::class, $this->piece(['printer_profile_id' => $other->id]))
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('printer.id', $other->id)
                ->where('printer.name', 'Outra')
                ->where('printer.note', null)
                ->etc());
    }

    public function test_an_archived_printer_falls_back_to_the_default_and_says_so(): void
    {
        $default = PrinterProfile::factory()->isDefault()->create(['name' => 'Predefinida']);
        $archived = PrinterProfile::factory()->archived()->create(['name' => 'Reformada']);

        $this->server()->tool(PricingCalculateTool::class, $this->piece(['printer_profile_id' => $archived->id]))
            ->assertOk()
            ->assertSee('arquivada')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('printer.id', $default->id)
                ->etc());
    }

    public function test_without_any_printer_it_says_the_machine_is_the_fallback(): void
    {
        $this->server()->tool(PricingCalculateTool::class, $this->piece())
            ->assertOk()
            ->assertSee('valores por omissão')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('printer.id', null)
                ->etc());
    }

    public function test_printers_list_shows_the_active_ones_with_the_default_first(): void
    {
        PrinterProfile::factory()->create(['name' => 'Bambu A1 Mini']);
        PrinterProfile::factory()->isDefault()->create(['name' => 'Bambu P1S']);
        PrinterProfile::factory()->archived()->create(['name' => 'Ender Velha']);

        $response = $this->server()->tool(PrintersListTool::class)
            ->assertOk()
            ->assertSee('hourly_cost')
            ->assertDontSee('Ender Velha');

        $printers = $this->structured($response)['printers'];

        $this->assertSame(['Bambu P1S', 'Bambu A1 Mini'], array_column($printers, 'name'));
        $this->assertSame([true, false], array_column($printers, 'is_default'));
    }

    public function test_printers_list_explains_an_empty_workshop(): void
    {
        $this->server()->tool(PrintersListTool::class)
            ->assertOk()
            ->assertSee('"printers":[]')
            ->assertSee('valores por omissão');
    }

    public function test_the_calculator_is_audited_without_arguments_and_writes_nothing(): void
    {
        $this->server()->tool(PricingCalculateTool::class, $this->piece())->assertOk();

        $activity = McpActivity::query()->where('tool', 'pricing_calculate')->sole();
        $this->assertSame(McpActivity::RESULT_OK, $activity->result);
        $this->assertNull($activity->arguments);
    }

    public function test_non_admins_get_nothing(): void
    {
        $production = User::factory()->production()->create();

        StudioServer::actingAs($production)
            ->tool(PricingCalculateTool::class, $this->piece())
            ->assertHasErrors(['Sem permissão.'])
            ->assertDontSee('retail_price');

        StudioServer::actingAs($production)
            ->tool(PrintersListTool::class)
            ->assertHasErrors(['Sem permissão.']);
    }

    /**
     * O conteudo estruturado da resposta, para comparar duas ferramentas.
     *
     * @return array<string, mixed>
     */
    private function structured(TestResponse $response): array
    {
        $captured = [];

        $response->assertOk()->assertStructuredContent(function (AssertableJson $json) use (&$captured): void {
            $captured = $json->toArray();
            $json->etc();
        });

        return $captured;
    }
}
