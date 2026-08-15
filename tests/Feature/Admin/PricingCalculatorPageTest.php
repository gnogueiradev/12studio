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
        PrinterProfile::factory()->isDefault()->create(['name' => 'Bambu Lab A1', 'hourly_rate_cents' => 20]);
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
                ->where('hourlyRateCents', 20)
            );
    }

    /**
     * O caso de referencia, agora atraves do HTTP: os mesmos numeros que o
     * PricingCalculatorTest fixa tem de chegar intactos ao Inertia. E aqui que
     * se apanha uma serializacao que arredonde pelo caminho.
     */
    public function test_the_page_returns_the_full_breakdown_for_the_reference_part(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', [
                'weight_grams' => 45,
                'hours' => 2,
                'minutes' => 30,
                'material_id' => $this->material->id,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.filamentCostMicros', 765_000)
                ->where('result.machineCostMicros', 500_000)
                ->where('result.handlingCostMicros', 150_000)
                ->where('result.failureReserveMicros', 63_250)
                ->where('result.productionCostMicros', 1_478_250)
                ->where('result.productionCostCents', 148)
                ->where('result.resalePriceCents', 300)
                ->where('result.retailPriceCents', 550)
                ->where('result.producerMarginBp', 5_073)
                ->where('result.resellerMarginBp', 4_545)
                ->where('result.retailBumped', false)
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
                // 150 min x 0,20 EUR/h = 0,50 EUR. Com 2,30 h dariam 0,46 EUR.
                ->where('result.machineCostMicros', 500_000)
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
                ->where('result.productionCostMicros', 677_700)
                ->where('result.resalePriceCents', 150)
                ->where('result.job.resalePriceCents', 900)
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
     * Sem impressora ativa nenhuma o calculo continua a sair — com o custo/hora
     * do config e um aviso. Uma calculadora que se recusasse a funcionar por
     * falta de configuracao era pior do que uma que explica no que caiu.
     */
    public function test_the_calculator_warns_when_it_falls_back_to_the_config_rate(): void
    {
        PrinterProfile::query()->update(['active' => false, 'is_default' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', [
                'weight_grams' => 45,
                'hours' => 2,
                'minutes' => 30,
                'material_id' => $this->material->id,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('usingFallbackRate', true)
                ->where('hourlyRateCents', 20)
                ->where('result.machineCostMicros', 500_000)
            );
    }

    /**
     * Uma impressora arquivada nao pode continuar a mandar no custo: o pedido
     * cai na predefinida, e a pagina devolve o id que REALMENTE usou para o
     * seletor nao ficar a mostrar uma maquina que ja nao conta.
     */
    public function test_an_archived_printer_falls_back_to_the_default(): void
    {
        $archived = PrinterProfile::factory()->archived()->create(['hourly_rate_cents' => 200]);
        $default = PrinterProfile::query()->where('is_default', true)->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', [
                'weight_grams' => 45,
                'hours' => 2,
                'minutes' => 30,
                'material_id' => $this->material->id,
                'printer_profile_id' => $archived->id,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('inputs.printer_profile_id', $default->id)
                ->where('result.machineCostMicros', 500_000)
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
