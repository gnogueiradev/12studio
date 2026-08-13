<?php

namespace Tests\Feature\Admin;

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
            'failure_reserve_percent' => '8',
            'minimum_resale_price' => '1.50',
            'retail_multiplier' => '1.75',
            'minimum_retail_multiplier' => '1.60',
            'batch_job_handling' => '0.20',
            'batch_unit_handling' => '0.10',
            'resale_multipliers' => [
                ['up_to' => '2.00', 'value' => '2.00'],
                ['up_to' => '5.00', 'value' => '1.90'],
                ['up_to' => '10.00', 'value' => '1.80'],
                ['up_to' => null, 'value' => '1.70'],
            ],
            'handling_tiers' => [
                ['up_to_grams' => 50, 'value' => '0.25'],
                ['up_to_grams' => 150, 'value' => '0.35'],
                ['up_to_grams' => 300, 'value' => '0.50'],
                ['up_to_grams' => 500, 'value' => '0.75'],
                ['up_to_grams' => null, 'value' => '1.00'],
            ],
        ];
    }

    public function test_the_index_sends_the_pricing_parameters_in_human_units(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.definicoes.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pricing.failure_reserve_percent', '8.00')
                ->where('pricing.retail_multiplier', '1.75')
                ->where('pricing.minimum_resale_price', '1.50')
                ->has('pricing.resale_multipliers', 4)
                ->has('pricing.handling_tiers', 5)
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
        PrinterProfile::factory()->isDefault()->create(['hourly_rate_cents' => 50]);

        $query = [
            'weight_grams' => 32,
            'hours' => 1,
            'minutes' => 30,
            'price_per_kg' => '17,00',
        ];

        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', $query))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.failureReserveMicros', 103_520)
                ->where('result.productionCostMicros', 1_647_520)
            );

        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...$this->validPayload(),
                'failure_reserve_percent' => '12',
            ]);

        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', $query))
            ->assertInertia(fn (AssertableInertia $page) => $page
                // (0,544 + 0,75) x 12% = 0,15528 EUR
                ->where('result.failureReserveMicros', 155_280)
                ->where('result.productionCostMicros', 1_699_280)
            );
    }

    /**
     * Uma tabela de faixas so funciona lida de cima para baixo. Sem a ordem, a
     * primeira faixa apanhava tudo e as outras eram codigo morto — sem erro
     * nenhum a dize-lo.
     */
    public function test_the_handling_tiers_must_be_in_ascending_order(): void
    {
        $payload = $this->validPayload();
        $payload['handling_tiers'][1]['up_to_grams'] = 20;

        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), $payload)
            ->assertSessionHasErrors('handling_tiers.1.up_to_grams');
    }

    /**
     * A ultima faixa apanha tudo o que passa das anteriores. Com tecto, uma
     * peca mais pesada do que o maior limiar saia sem manuseamento nenhum.
     */
    public function test_only_the_last_tier_may_be_open_ended(): void
    {
        $payload = $this->validPayload();
        $payload['handling_tiers'][4]['up_to_grams'] = 900;

        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), $payload)
            ->assertSessionHasErrors('handling_tiers.4.up_to_grams');

        $payload = $this->validPayload();
        $payload['handling_tiers'][2]['up_to_grams'] = null;

        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), $payload)
            ->assertSessionHasErrors('handling_tiers.2.up_to_grams');
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
        $this->assertSame(800, app(PricingSettings::class)->failureReserveBp());
    }

    public function test_non_admins_cannot_change_the_pricing(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch(route('admin.definicoes.precos'), $this->validPayload())
            ->assertForbidden();
    }
}
