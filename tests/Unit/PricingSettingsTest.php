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
        $this->assertSame(800, $this->pricing->failureReserveBp());
        $this->assertSame(150, $this->pricing->minimumResalePriceCents());
        $this->assertSame(17_500, $this->pricing->retailMultiplierBp());
        $this->assertSame(16_000, $this->pricing->minimumRetailMultiplierBp());
        $this->assertSame(20, $this->pricing->batchJobHandlingCents());
        $this->assertSame(10, $this->pricing->batchUnitHandlingCents());
        $this->assertSame(50, $this->pricing->fallbackHourlyRateCents());
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

        $this->assertSame(800, app(PricingSettings::class)->failureReserveBp());
        $this->assertCount(5, app(PricingSettings::class)->handlingTiers());
    }

    public function test_the_tiers_normalize_to_threshold_and_value_pairs(): void
    {
        $this->assertSame([
            ['threshold' => 50, 'value' => 25],
            ['threshold' => 150, 'value' => 35],
            ['threshold' => 300, 'value' => 50],
            ['threshold' => 500, 'value' => 75],
            ['threshold' => null, 'value' => 100],
        ], $this->pricing->handlingTiers());

        $this->assertSame([
            ['threshold' => 200, 'value' => 20_000],
            ['threshold' => 500, 'value' => 19_000],
            ['threshold' => 1_000, 'value' => 18_000],
            ['threshold' => null, 'value' => 17_000],
        ], $this->pricing->resaleMultipliers());
    }

    /**
     * O valor guardado e JSON, e JSON aceita tudo. Uma tabela de faixas com a
     * forma errada tem de cair para o config em vez de calcular com lixo — um
     * manuseamento a zero sairia num preco plausivel que ninguem questionava.
     */
    public function test_a_malformed_tier_table_falls_back_to_the_config(): void
    {
        $settings = app(SettingService::class);

        $settings->set(PricingSettings::KEY_HANDLING_TIERS, 'nao e uma tabela');
        $this->assertCount(5, app(PricingSettings::class)->handlingTiers());

        $settings->set(PricingSettings::KEY_HANDLING_TIERS, [['up_to_grams' => 50]]);
        $this->assertCount(5, app(PricingSettings::class)->handlingTiers());
    }

    /**
     * A ultima faixa TEM de ser aberta. Se a mais pesada tivesse tecto, uma
     * peca acima dele nao pagava manuseamento nenhum.
     */
    public function test_a_tier_table_without_an_open_last_band_is_rejected(): void
    {
        app(SettingService::class)->set(PricingSettings::KEY_HANDLING_TIERS, [
            ['up_to_grams' => 50, 'cents' => 25],
            ['up_to_grams' => 500, 'cents' => 75],
        ]);

        $this->assertCount(5, app(PricingSettings::class)->handlingTiers());
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

        $this->assertSame(800, $after->failureReserveBp());
        $this->assertSame(150, $after->minimumResalePriceCents());
        $this->assertSame(17_500, $after->retailMultiplierBp());
        $this->assertSame(16_000, $after->minimumRetailMultiplierBp());
        $this->assertSame($this->pricing->resaleMultipliers(), $after->resaleMultipliers());
        $this->assertSame($this->pricing->handlingTiers(), $after->handlingTiers());
    }

    public function test_resetting_brings_the_config_defaults_back(): void
    {
        app(SettingService::class)->set(PricingSettings::KEY_FAILURE_RESERVE_BP, 1_250);

        app(PricingSettings::class)->resetToDefaults();

        $this->assertSame(800, app(PricingSettings::class)->failureReserveBp());
        $this->assertDatabaseMissing('settings', ['key' => PricingSettings::KEY_FAILURE_RESERVE_BP]);
    }
}
