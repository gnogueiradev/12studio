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
        $this->assertSame(142_000, $this->pricing->electricityPriceMicrosPerKwh());
        $this->assertSame(8_000_000, $this->pricing->laborRateMicrosPerHour());
        $this->assertSame(5, $this->pricing->activeLaborMinutes());
        $this->assertSame(5, $this->pricing->setupLaborMinutes());
        $this->assertSame(500, $this->pricing->failureRateBp());
        $this->assertSame(4_000, $this->pricing->targetWholesaleMarginBp());
        $this->assertSame(4_000, $this->pricing->targetResellerMarginBp());
        $this->assertSame(150, $this->pricing->minimumWholesalePriceCents());
        $this->assertSame(0, $this->pricing->salesChannelFixedFeeCents());
        $this->assertSame(0, $this->pricing->salesChannelPercentageFeeBp());
    }

    /** A maquina imaginaria, para quando nao ha nenhuma impressora ativa. */
    public function test_the_fallback_printer_comes_from_the_config(): void
    {
        $this->assertSame(145, $this->pricing->fallbackPrinterPowerWatts());
        $this->assertSame(40_000, $this->pricing->fallbackPrinterPurchasePriceCents());
        $this->assertSame(4_000, $this->pricing->fallbackPrinterLifetimeHours());
        $this->assertSame(40_000, $this->pricing->fallbackPrinterMaintenanceMicrosPerHour());
    }

    /**
     * Os quatro valores da maquina de recurso NAO sao definicoes: nao estao na
     * lista de chaves e nao ha nada na tabela `settings` que os sobreponha.
     * Quem manda no custo da maquina sao os perfis de impressora.
     */
    public function test_the_fallback_printer_is_not_a_setting(): void
    {
        foreach (PricingSettings::KEYS as $key) {
            $this->assertStringNotContainsString('fallback_printer', $key);
        }

        app(SettingService::class)->set('pricing.fallback_printer_power_watts', 999);

        $this->assertSame(145, app(PricingSettings::class)->fallbackPrinterPowerWatts());
    }

    public function test_a_saved_setting_overrides_the_config(): void
    {
        app(SettingService::class)->set(PricingSettings::KEY_FAILURE_RATE_BP, 1_250);

        $this->assertSame(1_250, app(PricingSettings::class)->failureRateBp());
    }

    /**
     * A tabela `settings` pode nao estar la — o SettingService le em todos os
     * pedidos, incluindo num deploy antes de as migracoes correrem. A
     * calculadora nao pode ir abaixo por isso; fica nos valores do config.
     */
    public function test_a_missing_settings_table_falls_back_to_the_config(): void
    {
        Schema::drop('settings');

        $this->assertSame(500, app(PricingSettings::class)->failureRateBp());
        $this->assertSame(142_000, app(PricingSettings::class)->electricityPriceMicrosPerKwh());
        $this->assertSame(4_000, app(PricingSettings::class)->targetWholesaleMarginBp());
    }

    /**
     * O valor guardado e JSON, e JSON aceita tudo. Uma definicao com o tipo
     * errado tem de cair para o config em vez de virar zero em silencio: uma
     * tarifa a zero, ou uma taxa de falhas a zero, sairiam num preco plausivel
     * que ninguem questionava.
     */
    public function test_a_setting_stored_with_the_wrong_type_falls_back_to_the_config(): void
    {
        $settings = app(SettingService::class);

        $settings->set(PricingSettings::KEY_ELECTRICITY_PRICE_MICROS_PER_KWH, 'nao e um numero');
        $this->assertSame(142_000, app(PricingSettings::class)->electricityPriceMicrosPerKwh());

        $settings->set(PricingSettings::KEY_FAILURE_RATE_BP, '');
        $this->assertSame(500, app(PricingSettings::class)->failureRateBp());

        $settings->set(PricingSettings::KEY_TARGET_WHOLESALE_MARGIN_BP, ['40']);
        $this->assertSame(4_000, app(PricingSettings::class)->targetWholesaleMarginBp());
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

        $this->assertSame(142_000, $after->electricityPriceMicrosPerKwh());
        $this->assertSame(8_000_000, $after->laborRateMicrosPerHour());
        $this->assertSame(5, $after->activeLaborMinutes());
        $this->assertSame(5, $after->setupLaborMinutes());
        $this->assertSame(500, $after->failureRateBp());
        $this->assertSame(4_000, $after->targetWholesaleMarginBp());
        $this->assertSame(4_000, $after->targetResellerMarginBp());
        $this->assertSame(150, $after->minimumWholesalePriceCents());
        $this->assertSame(0, $after->salesChannelFixedFeeCents());
        $this->assertSame(0, $after->salesChannelPercentageFeeBp());
    }

    /**
     * O campo que o round-trip generico nao chega para proteger: a tarifa tem
     * QUATRO casas, e mostra-la com duas perdia os 0,0020 EUR/kWh que separam
     * um tarifario do seguinte. Guardar sem tocar em nada baixava a conta da
     * luz em 1,4% sem ninguem pedir.
     */
    public function test_the_four_decimal_tariff_survives_the_form(): void
    {
        $this->assertSame('0.1420', $this->pricing->toForm()['electricity_price']);

        app(SettingService::class)->setMany($this->pricing->fromForm([
            ...$this->pricing->toForm(),
            'electricity_price' => '0,1735',
        ]));

        $this->assertSame(173_500, app(PricingSettings::class)->electricityPriceMicrosPerKwh());
        $this->assertSame('0.1735', app(PricingSettings::class)->toForm()['electricity_price']);
    }

    public function test_resetting_brings_the_config_defaults_back(): void
    {
        app(SettingService::class)->set(PricingSettings::KEY_FAILURE_RATE_BP, 1_250);

        app(PricingSettings::class)->resetToDefaults();

        $this->assertSame(500, app(PricingSettings::class)->failureRateBp());
        $this->assertDatabaseMissing('settings', ['key' => PricingSettings::KEY_FAILURE_RATE_BP]);
    }
}
