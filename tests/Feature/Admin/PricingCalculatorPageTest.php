<?php

namespace Tests\Feature\Admin;

use App\Models\Material;
use App\Models\PrinterProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PricingCalculatorPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /**
     * O filamento de referencia, a 17,00 EUR/kg. Todos os casos deste ficheiro
     * o mandam no URL: o preco a mao deixou de existir, portanto sem material
     * escolhido nao ha calculo nenhum para testar.
     */
    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        PrinterProfile::factory()->isDefault()->create([
            'name' => 'Bambu Lab A1',
            'average_power_watts' => 145,
            'purchase_price_cents' => 40_000,
            'lifetime_hours' => 4_000,
            'maintenance_micros_per_hour' => 40_000,
        ]);
        $this->material = Material::factory()->create(['price_per_kg_cents' => 1_700]);
    }

    /**
     * Sem tempo nao ha calculo — e a regra fundamental desta versao. A pagina
     * abre com os campos vazios e sem preco nenhum, em vez de inventar um a
     * partir da gramagem, que era exatamente o que se veio corrigir.
     */
    public function test_the_page_opens_without_a_result_until_there_is_a_print_time(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.calculadora'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/calculadora/index')
                ->where('result', null)
                ->where('usingFallbackRate', false)
                // 145 W x 0,1420 + 400/4000 + 0,04 = 0,16059 EUR/h
                ->where('hourlyCostMicros', 160_590)
                ->where('defaultActiveLaborMinutes', 5)
            );
    }

    /**
     * O caso de referencia, agora atraves do HTTP: os mesmos numeros que o
     * PricingCalculatorTest fixa tem de chegar intactos ao Inertia. E aqui que
     * se apanha uma serializacao que arredonde pelo caminho — 0,06177 EUR de
     * eletricidade nao sobrevive a um `round()` distraido.
     */
    public function test_the_page_returns_the_full_breakdown_for_the_reference_part(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', [
                'weight_grams' => 50,
                'hours' => 3,
                'minutes' => 0,
                'material_id' => $this->material->id,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.filamentCostMicros', 850_000)
                ->where('result.electricityCostMicros', 61_770)
                ->where('result.depreciationCostMicros', 300_000)
                ->where('result.maintenanceCostMicros', 120_000)
                ->where('result.laborCostMicros', 666_667)
                ->where('result.baseProductionCostMicros', 1_998_437)
                ->where('result.failureCostMicros', 105_181)
                ->where('result.productionCostMicros', 2_103_618)
                ->where('result.productionCostCents', 210)
                ->where('result.wholesalePriceCents', 400)
                ->where('result.retailPriceCents', 700)
                ->where('result.wholesaleMarginBp', 4_741)
                ->where('result.directMarginBp', 6_995)
                ->where('result.resellerMarginBp', 4_286)
                ->where('result.resellerMarkupBp', 7_500)
            );
    }

    /**
     * "2h30" sao 150 minutos e nao 2,30 horas. Os dois campos separados existem
     * para nao haver leitura ambigua nenhuma; este teste fixa a conversao.
     */
    public function test_hours_and_minutes_become_a_single_minute_count(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', [
                'weight_grams' => 45,
                'hours' => 2,
                'minutes' => 30,
                'material_id' => $this->material->id,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                // 150 min x 0,10 EUR/h = 0,25 EUR. Com 2,30 h dariam 0,23 EUR.
                ->where('result.depreciationCostMicros', 250_000)
                ->where('inputs.hours', 2)
                ->where('inputs.minutes', 30)
            );
    }

    public function test_the_batch_mode_divides_the_job_by_the_quantity(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', [
                'mode' => 'batch',
                'weight_grams' => 132,
                'hours' => 4,
                'minutes' => 20,
                'quantity' => 6,
                'material_id' => $this->material->id,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.mode', 'batch')
                ->where('result.filamentCostMicros', 374_000)
                ->where('result.productionCostMicros', 1_334_484)
                ->where('result.wholesalePriceCents', 250)
                ->where('result.job.wholesalePriceCents', 1_500)
            );
    }

    /**
     * Os dois campos de custo que substituiram o saco unico, e o tempo de
     * trabalho por peca. Vao com virgula de proposito: sem entrarem na lista do
     * prepareForValidation, "0,20" chegava ao validador como texto.
     */
    public function test_the_packaging_components_and_labor_reach_the_calculation(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', [
                'weight_grams' => 50,
                'hours' => 3,
                'minutes' => 0,
                'material_id' => $this->material->id,
                'packaging_cost' => '0,20',
                'components_cost' => '0,30',
                'active_labor_minutes' => 20,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.packagingCostMicros', 200_000)
                ->where('result.componentsCostMicros', 300_000)
                ->where('result.laborCostMicros', 2_666_667)
                ->where('result.laborMinutes', 20)
            );
    }

    /**
     * Um campo de trabalho vazio quer dizer "usa a definicao global", e nao
     * zero. Zero e uma afirmacao — "esta peca nao leva trabalho nenhum" — e tem
     * de continuar a poder ser escrita.
     */
    public function test_an_empty_active_labor_falls_back_to_the_global_default(): void
    {
        $query = [
            'weight_grams' => 50,
            'hours' => 3,
            'minutes' => 0,
            'material_id' => $this->material->id,
        ];

        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', $query))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.laborMinutes', 5)
                ->where('inputs.active_labor_minutes', null)
            );

        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', [...$query, 'active_labor_minutes' => 0]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.laborMinutes', 0)
                ->where('result.laborCostMicros', 0)
            );
    }

    /**
     * O preco por kg vem do MATERIAL e de mais lado nenhum. Este teste fixa a
     * remocao do preco a mao: um "price_per_kg" no URL — de um link antigo, ou
     * escrito a mao — nao pode mandar no custo, senao o preco saia de um numero
     * que nao corresponde a filamento nenhum em stock.
     */
    public function test_a_typed_price_per_kg_in_the_url_is_ignored(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', [
                'weight_grams' => 45,
                'hours' => 2,
                'minutes' => 30,
                'material_id' => $this->material->id,
                'price_per_kg' => '99,00',
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                // 45 g x 0,017 EUR/g. A 99,00 EUR/kg dariam 4 455 000.
                ->where('result.filamentCostMicros', 765_000)
                ->missing('inputs.price_per_kg')
            );
    }

    /**
     * Sem filamento escolhido nao ha preco, mesmo com peso e tempo. Como o
     * filamento so pode vir da loja, sem material o custo do plastico era zero —
     * e um preco que finge que o plastico e de graca engana mais do que preco
     * nenhum.
     */
    public function test_without_a_material_there_is_no_price(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', [
                'weight_grams' => 45,
                'hours' => 2,
                'minutes' => 30,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('inputs.material_id', null)
                ->where('result', null)
            );
    }

    /**
     * A pagina abre vazia: nenhum filamento vem pre-escolhido. A impressora tem
     * predefinida, o material nao — sao dois filamentos com precos diferentes e
     * adivinhar qual e que se ia usar era inventar meio orcamento.
     */
    public function test_the_page_opens_without_a_material_chosen(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.calculadora'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('inputs.material_id', null)
                ->has('materials', 1)
            );
    }

    /**
     * Sem impressora ativa nenhuma o calculo continua a sair — com os valores
     * de recurso do config e um aviso. Uma calculadora que se recusasse a
     * funcionar por falta de configuracao era pior do que uma que explica no
     * que caiu.
     */
    public function test_the_calculator_warns_when_it_falls_back_to_the_config_machine(): void
    {
        PrinterProfile::query()->update(['active' => false, 'is_default' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', [
                'weight_grams' => 50,
                'hours' => 3,
                'minutes' => 0,
                'material_id' => $this->material->id,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('usingFallbackRate', true)
                ->where('hourlyCostMicros', 160_590)
                ->where('result.depreciationCostMicros', 300_000)
            );
    }

    /**
     * Uma impressora arquivada nao pode continuar a mandar no custo: o pedido
     * cai na predefinida, e a pagina devolve o id que REALMENTE usou para o
     * seletor nao ficar a mostrar uma maquina que ja nao conta.
     */
    public function test_an_archived_printer_falls_back_to_the_default(): void
    {
        $archived = PrinterProfile::factory()->archived()->create([
            'average_power_watts' => 900,
            'purchase_price_cents' => 400_000,
        ]);
        $default = PrinterProfile::query()->where('is_default', true)->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', [
                'weight_grams' => 50,
                'hours' => 3,
                'minutes' => 0,
                'material_id' => $this->material->id,
                'printer_profile_id' => $archived->id,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('inputs.printer_profile_id', $default->id)
                ->where('result.depreciationCostMicros', 300_000)
                ->where('result.electricityCostMicros', 61_770)
            );
    }

    public function test_a_negative_weight_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', ['weight_grams' => -5]))
            ->assertSessionHasErrors('weight_grams');
    }

    public function test_non_admins_cannot_open_the_calculator(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.calculadora'))
            ->assertForbidden();
    }
}
