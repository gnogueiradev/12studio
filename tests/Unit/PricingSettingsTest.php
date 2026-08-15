<?php

namespace Tests\Unit;

use App\Services\PricingSettings;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PricingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private PricingSettings $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pricing = app(PricingSettings::class);
    }

    public function test_the_defaults_come_from_the_config_until_someone_saves_a_setting(): void
    {
        $this->assertSame(500, $this->pricing->failureReserveBp());
        $this->assertSame(150, $this->pricing->minimumResalePriceCents());
        $this->assertSame(17_000, $this->pricing->resaleMultiplierBp());
        $this->assertSame(17_500, $this->pricing->retailMultiplierBp());
        $this->assertSame(16_000, $this->pricing->minimumRetailMultiplierBp());
        $this->assertSame(15, $this->pricing->handlingCostCents());
        $this->assertSame(20, $this->pricing->batchJobHandlingCents());
        $this->assertSame(10, $this->pricing->batchUnitHandlingCents());
        $this->assertSame(20, $this->pricing->fallbackHourlyRateCents());
    }

    public function test_a_saved_setting_overrides_the_config(): void
    {
        app(SettingService::class)->set(PricingSettings::KEY_FAILURE_RESERVE_BP, 1_250);

        $this->assertSame(1_250, app(PricingSettings::class)->failureReserveBp());
    }

    /**
     * A tabela `settings` pode nao estar la — o SettingService le em todos os
     * pedidos, incluindo num deploy antes de as migracoes correrem. A
     * calculadora nao pode ir abaixo por isso; fica nos valores do config.
     */
    public function test_a_missing_settings_table_falls_back_to_the_config(): void
    {
        Schema::drop('settings');

        $this->assertSame(500, app(PricingSettings::class)->failureReserveBp());
        $this->assertSame(15, app(PricingSettings::class)->handlingCostCents());
        $this->assertSame(17_000, app(PricingSettings::class)->resaleMultiplierBp());
    }

    /**
     * O valor guardado e JSON, e JSON aceita tudo. Uma definicao com o tipo
     * errado tem de cair para o config em vez de virar zero em silencio: um
     * manuseamento a zero, ou uma reserva de falhas a zero, sairiam num preco
     * plausivel que ninguem questionava.
     */
    public function test_a_setting_stored_with_the_wrong_type_falls_back_to_the_config(): void
    {
        $settings = app(SettingService::class);

        $settings->set(PricingSettings::KEY_HANDLING_COST_CENTS, 'nao e um numero');
        $this->assertSame(15, app(PricingSettings::class)->handlingCostCents());

        $settings->set(PricingSettings::KEY_FAILURE_RESERVE_BP, '');
        $this->assertSame(500, app(PricingSettings::class)->failureReserveBp());

        $settings->set(PricingSettings::KEY_RESALE_MULTIPLIER_BP, ['1.70']);
        $this->assertSame(17_000, app(PricingSettings::class)->resaleMultiplierBp());
    }

    /**
     * Abrir as definicoes e guardar sem tocar em nada nao pode mexer num
     * centimo dos precos da loja.
     */
    public function test_the_form_round_trips_without_drifting(): void
    {
        $before = $this->pricing->toForm();

        app(SettingService::class)->setMany($this->pricing->fromForm($before));

        $after = app(PricingSettings::class);

        $this->assertSame(500, $after->failureReserveBp());
        $this->assertSame(150, $after->minimumResalePriceCents());
        // "1.70" -> 17000 e "0.15" -> 15 sem desvio: o round-trip passa por
        // strings decimais, e e aqui que se apanharia um float a perder o fio.
        $this->assertSame(17_000, $after->resaleMultiplierBp());
        $this->assertSame(17_500, $after->retailMultiplierBp());
        $this->assertSame(16_000, $after->minimumRetailMultiplierBp());
        $this->assertSame(15, $after->handlingCostCents());
        $this->assertSame(20, $after->batchJobHandlingCents());
        $this->assertSame(10, $after->batchUnitHandlingCents());
    }

    public function test_resetting_brings_the_config_defaults_back(): void
    {
        app(SettingService::class)->set(PricingSettings::KEY_FAILURE_RESERVE_BP, 1_250);

        app(PricingSettings::class)->resetToDefaults();

        $this->assertSame(500, app(PricingSettings::class)->failureReserveBp());
        $this->assertDatabaseMissing('settings', ['key' => PricingSettings::KEY_FAILURE_RESERVE_BP]);
    }
}
