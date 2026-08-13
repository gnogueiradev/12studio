<?php

namespace Tests\Feature\Admin;

use App\Models\Color;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        PrinterProfile::factory()->isDefault()->create(['name' => 'Bambu Lab A1', 'hourly_rate_cents' => 50]);
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
                ->where('hourlyRateCents', 50)
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
                'weight_grams' => 32,
                'hours' => 1,
                'minutes' => 30,
                'price_per_kg' => '17,00',
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.filamentCostMicros', 544_000)
                ->where('result.machineCostMicros', 750_000)
                ->where('result.handlingCostMicros', 250_000)
                ->where('result.failureReserveMicros', 103_520)
                ->where('result.productionCostMicros', 1_647_520)
                ->where('result.productionCostCents', 165)
                ->where('result.resalePriceCents', 350)
                ->where('result.retailPriceCents', 600)
                ->where('result.producerMarginBp', 5_293)
                ->where('result.resellerMarginBp', 4_167)
                ->where('result.retailBumped', false)
            );
    }

    /**
     * "1h30" sao 90 minutos e nao 1,30 horas. Os dois campos separados existem
     * para nao haver leitura ambigua nenhuma; este teste fixa a conversao.
     */
    public function test_hours_and_minutes_become_a_single_minute_count(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', [
                'weight_grams' => 32,
                'hours' => 1,
                'minutes' => 30,
                'price_per_kg' => '17,00',
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                // 90 min x 0,50 EUR/h = 0,75 EUR. Com 1,30 h dariam 0,65 EUR.
                ->where('result.machineCostMicros', 750_000)
                ->where('inputs.hours', 1)
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
                'price_per_kg' => '17,00',
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.mode', 'batch')
                ->where('result.productionCostMicros', 927_253)
                ->where('result.resalePriceCents', 200)
                ->where('result.job.resalePriceCents', 1_200)
            );
    }

    /**
     * A cor manda no preco por kg quando ha uma escolhida: e ela que conhece o
     * override sobre o material. Deixar o cliente mandar o numero abria a porta
     * a um custo que nao corresponde a filamento nenhum que exista.
     */
    public function test_the_chosen_colour_overrides_the_typed_price_per_kg(): void
    {
        $material = Material::factory()->create(['price_per_kg_cents' => 1_700]);
        $color = Color::factory()->create(['material_id' => $material->id, 'price_per_kg_cents' => null]);

        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', [
                'weight_grams' => 32,
                'hours' => 1,
                'minutes' => 30,
                'color_id' => $color->id,
                'price_per_kg' => '99,00',
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.filamentCostMicros', 544_000)
                ->where('inputs.price_per_kg', '17.00')
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
                'weight_grams' => 32,
                'hours' => 1,
                'minutes' => 30,
                'price_per_kg' => '17,00',
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('usingFallbackRate', true)
                ->where('hourlyRateCents', 50)
                ->where('result.machineCostMicros', 750_000)
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
                'weight_grams' => 32,
                'hours' => 1,
                'minutes' => 30,
                'price_per_kg' => '17,00',
                'printer_profile_id' => $archived->id,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('inputs.printer_profile_id', $default->id)
                ->where('result.machineCostMicros', 750_000)
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
