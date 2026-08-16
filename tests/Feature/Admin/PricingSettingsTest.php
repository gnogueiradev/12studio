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
            'electricity_price' => '0.1420',
            'labor_rate' => '8.00',
            'active_labor_minutes' => 5,
            'setup_labor_minutes' => 5,
            'failure_rate_percent' => '5',
            'wholesale_margin_percent' => '40',
            'reseller_margin_percent' => '40',
            'minimum_wholesale_price' => '1.50',
            'channel_fixed_fee' => '0.00',
            'channel_percentage_fee' => '0',
        ];
    }

    public function test_the_index_sends_the_pricing_parameters_in_human_units(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.definicoes.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pricing.electricity_price', '0.1420')
                ->where('pricing.labor_rate', '8.00')
                ->where('pricing.active_labor_minutes', 5)
                ->where('pricing.failure_rate_percent', '5.00')
                ->where('pricing.wholesale_margin_percent', '40.00')
                ->where('pricing.reseller_margin_percent', '40.00')
                ->where('pricing.minimum_wholesale_price', '1.50')
            );
    }

    public function test_the_admin_can_change_the_failure_rate(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...$this->validPayload(),
                'failure_rate_percent' => '12,5',
            ])
            ->assertRedirect(route('admin.definicoes.index'));

        $this->assertSame(1_250, app(PricingSettings::class)->failureRateBp());
    }

    /**
     * A prova de que as definicoes sao mesmo lidas pelo calculador, e nao so
     * gravadas: com a tarifa da luz a dobrar, a mesma peca de referencia fica
     * mais cara.
     */
    public function test_saving_the_pricing_settings_changes_the_calculated_price(): void
    {
        PrinterProfile::factory()->isDefault()->create([
            'average_power_watts' => 145,
            'purchase_price_cents' => 40_000,
            'lifetime_hours' => 4_000,
            'maintenance_micros_per_hour' => 40_000,
        ]);
        $material = Material::factory()->create(['price_per_kg_cents' => 1_700]);

        $query = [
            'weight_grams' => 50,
            'hours' => 3,
            'minutes' => 0,
            'material_id' => $material->id,
        ];

        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', $query))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.electricityCostMicros', 61_770)
                ->where('result.productionCostMicros', 2_103_618)
            );

        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...$this->validPayload(),
                'electricity_price' => '0,2840',
            ]);

        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', $query))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.electricityCostMicros', 123_540)
                ->where('result.productionCostMicros', 2_168_639)
            );
    }

    /**
     * Vao com virgula de proposito: e a unica cobertura de que entraram na
     * lista do prepareForValidation — sem isso, "0,1735" chegava ao validador
     * como texto e o `numeric` rejeitava-o.
     */
    public function test_the_decimal_fields_accept_a_comma(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...$this->validPayload(),
                'electricity_price' => '0,1735',
                'labor_rate' => '12,50',
                'minimum_wholesale_price' => '2,00',
            ])
            ->assertRedirect(route('admin.definicoes.index'));

        $pricing = app(PricingSettings::class);

        $this->assertSame(173_500, $pricing->electricityPriceMicrosPerKwh());
        $this->assertSame(12_500_000, $pricing->laborRateMicrosPerHour());
        $this->assertSame(200, $pricing->minimumWholesalePriceCents());
    }

    /**
     * O preco sai de custo / (1 - margem). A 100% isso era uma divisao por
     * zero, e o formulario e a primeira linha de defesa contra ela.
     */
    public function test_a_margin_of_one_hundred_percent_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...$this->validPayload(),
                'wholesale_margin_percent' => '100',
            ])
            ->assertSessionHasErrors('wholesale_margin_percent');

        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...$this->validPayload(),
                'reseller_margin_percent' => '100',
            ])
            ->assertSessionHasErrors('reseller_margin_percent');
    }

    /** Pelo mesmo motivo: o custo divide-se por (1 - taxa de falhas). */
    public function test_an_absurd_failure_rate_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...$this->validPayload(),
                'failure_rate_percent' => '80',
            ])
            ->assertSessionHasErrors('failure_rate_percent');
    }

    /**
     * As comissoes do canal sao definicoes normais, mas com um efeito
     * diferente: nao mexem no preco, so no que sobra dele.
     */
    public function test_the_channel_fees_are_saved_without_moving_the_price(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...$this->validPayload(),
                'channel_fixed_fee' => '0,35',
                'channel_percentage_fee' => '10',
            ])
            ->assertRedirect(route('admin.definicoes.index'));

        $pricing = app(PricingSettings::class);

        $this->assertSame(35, $pricing->salesChannelFixedFeeCents());
        $this->assertSame(1_000, $pricing->salesChannelPercentageFeeBp());
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
        $this->assertSame(500, app(PricingSettings::class)->failureRateBp());
    }

    public function test_non_admins_cannot_change_the_pricing(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch(route('admin.definicoes.precos'), $this->validPayload())
            ->assertForbidden();
    }
}
