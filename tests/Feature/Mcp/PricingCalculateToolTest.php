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

    public function test_it_gives_the_same_numbers_as_the_variant_preview(): void
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
            'active_labor_minutes' => 4,
        ]);

        $saved = $this->structured($this->server()->tool(PricingPreviewTool::class, ['variant_id' => $variant->id]));
        $free = $this->structured($this->server()->tool(PricingCalculateTool::class, $this->piece([
            'weight_grams' => 80,
            'hours' => 1,
            'minutes' => 35,
            'packaging_cost' => '0,35',
            'components_cost' => '1.20',
            'active_labor_minutes' => 4,
        ])));

        $this->assertSame($saved['suggested'], $free['suggested']);
        $this->assertSame($saved['costs'], $free['costs']);
        $this->assertSame($saved['margins_percent'], $free['margins_percent']);
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
