<?php

namespace Tests\Feature\Admin;

use App\Models\Material;
use App\Models\PrinterProfile;
use App\Models\User;
use App\Services\PricingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PricingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'failure_reserve_percent' => '5',
            'minimum_resale_price' => '1.50',
            'resale_multiplier' => '1.70',
            'retail_multiplier' => '1.75',
            'minimum_retail_multiplier' => '1.60',
            'handling_cost' => '0.15',
            'batch_job_handling' => '0.20',
            'batch_unit_handling' => '0.10',
        ];
    }

    public function test_the_index_sends_the_pricing_parameters_in_human_units(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.definicoes.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pricing.failure_reserve_percent', '5.00')
                ->where('pricing.resale_multiplier', '1.70')
                ->where('pricing.retail_multiplier', '1.75')
                ->where('pricing.minimum_resale_price', '1.50')
                ->where('pricing.handling_cost', '0.15')
            );
    }

    public function test_the_admin_can_change_the_failure_reserve(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...$this->validPayload(),
                'failure_reserve_percent' => '12,5',
            ])
            ->assertRedirect(route('admin.definicoes.index'));

        $this->assertSame(1_250, app(PricingSettings::class)->failureReserveBp());
    }

    /**
     * A prova de que as definicoes sao mesmo lidas pelo calculador, e nao so
     * gravadas: com 12% de reserva a mesma peca de referencia fica mais cara.
     */
    public function test_saving_the_pricing_settings_changes_the_calculated_price(): void
    {
        PrinterProfile::factory()->isDefault()->create(['hourly_rate_cents' => 20]);
        $material = Material::factory()->create(['price_per_kg_cents' => 1_700]);

        $query = [
            'weight_grams' => 45,
            'hours' => 2,
            'minutes' => 30,
            'material_id' => $material->id,
        ];

        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', $query))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.failureReserveMicros', 63_250)
                ->where('result.productionCostMicros', 1_478_250)
            );

        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...$this->validPayload(),
                'failure_reserve_percent' => '12',
            ]);

        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', $query))
            ->assertInertia(fn (AssertableInertia $page) => $page
                // (0,765 + 0,50) x 12% = 0,1518 EUR
                ->where('result.failureReserveMicros', 151_800)
                ->where('result.productionCostMicros', 1_566_800)
            );
    }

    /**
     * Os dois parametros que substituiram as tabelas de faixas. Vao com virgula
     * de proposito: e a unica cobertura de que entraram na lista do
     * prepareForValidation — sem isso, "1,90" chegava ao validador como texto.
     */
    public function test_the_admin_can_change_the_resale_multiplier_and_the_handling_cost(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...$this->validPayload(),
                'resale_multiplier' => '1,90',
                'handling_cost' => '0,25',
            ])
            ->assertRedirect(route('admin.definicoes.index'));

        $pricing = app(PricingSettings::class);

        $this->assertSame(19_000, $pricing->resaleMultiplierBp());
        $this->assertSame(25, $pricing->handlingCostCents());
    }

    /**
     * Com o minimo acima do multiplicador normal, o arredondamento comercial
     * deixava de ter efeito nenhum e TODOS os precos passavam pela rede de
     * seguranca.
     */
    public function test_the_minimum_retail_multiplier_cannot_exceed_the_retail_multiplier(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...$this->validPayload(),
                'minimum_retail_multiplier' => '2.00',
            ])
            ->assertSessionHasErrors('minimum_retail_multiplier');
    }

    /**
     * Um multiplicador abaixo de 1,00x da lucro negativo e tira o
     * Micros::divRound do dominio nao negativo que ele documenta.
     */
    public function test_a_multiplier_below_one_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...$this->validPayload(),
                'retail_multiplier' => '0.9',
            ])
            ->assertSessionHasErrors('retail_multiplier');

        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...$this->validPayload(),
                'resale_multiplier' => '0.9',
            ])
            ->assertSessionHasErrors('resale_multiplier');
    }

    /**
     * O formulario da moeda tem rota propria e continua a gravar sozinho. E
     * este teste que guarda a generalizacao do SettingController: se alguem
     * juntar os dois endpoints, e aqui que se ve.
     */
    public function test_the_currency_form_still_saves_on_its_own(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.update'), ['currency' => 'CHF'])
            ->assertRedirect(route('admin.definicoes.index'));

        $this->assertDatabaseHas('settings', ['key' => 'currency']);
        $this->assertSame(500, app(PricingSettings::class)->failureReserveBp());
    }

    public function test_non_admins_cannot_change_the_pricing(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch(route('admin.definicoes.precos'), $this->validPayload())
            ->assertForbidden();
    }
}
