<?php

namespace Tests\Unit;

use App\Services\PricingCalculator;
use App\Services\PricingSettings;
use App\Services\SettingService;
use App\Support\Micros;
use App\Support\PriceTier;
use App\Support\PriceTiers;
use App\Support\PricingInput;
use App\Support\PricingResult;
use App\Support\Rate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Os tres precos sugeridos (Minimo, Recomendado, Premium), COM IVA a 23% —
 * como a loja os calcula de verdade.
 *
 * Os numeros dos casos reais sao os de producao: PLA Mate a 16,99 EUR/kg, a
 * Bambu Lab A1 por omissao e a mao de obra a 2 EUR/h que esta nas definicoes.
 */
class PricingTiersTest extends TestCase
{
    use RefreshDatabase;

    private PricingCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingService::class)->set(PricingSettings::KEY_LABOR_RATE_MICROS_PER_HOUR, 2_000_000);

        $this->calculator = app(PricingCalculator::class);
    }

    private function part(
        int $grams,
        int $minutes,
        bool $quote = false,
        int $plates = 1,
        int $colorChanges = 0,
        int $designMinutes = 0,
        int $quantity = 1,
        string $mode = PricingInput::MODE_PER_UNIT,
        ?int $referenceCents = null,
    ): PricingResult {
        return $this->calculator->calculate(new PricingInput(
            mode: $mode,
            weightGrams: $grams,
            minutes: $minutes,
            pricePerKgCents: 1_699,
            printerPowerWatts: 145,
            printerPurchasePriceCents: 40_000,
            printerLifetimeHours: 4_000,
            printerMaintenanceMicrosPerHour: 40_000,
            vatRateBp: 2_300,
            quantity: $quantity,
            quote: $quote,
            plates: $plates,
            manualColorChanges: $colorChanges,
            designMinutes: $designMinutes,
            referenceFilamentCostCents: $referenceCents,
        ));
    }

    /** O caso que motivou isto: 66 g, 4h37, 2 placas, 1 troca de cor. */
    private function realCase(): PricingResult
    {
        return $this->part(grams: 66, minutes: 277, quote: true, plates: 2, colorChanges: 1);
    }

    private static function euros(int $micros): string
    {
        return number_format($micros / Micros::PER_EURO, 2, ',', '');
    }

    public function test_the_real_case_is_now_paid_by_the_hour(): void
    {
        $result = $this->realCase();
        $tiers = $result->tiers;

        // 20 min de minimo (5 + 15) + 5 da placa extra + 5 da troca de cor.
        $this->assertSame(
            ['base' => 5, 'quote_minimum' => 15, 'plates' => 5, 'color_changes' => 5],
            $result->laborBreakdownMinutes,
        );
        $this->assertSame(30, $result->laborMinutes);

        $this->assertGreaterThanOrEqual(9_990_000, $tiers->minimum->priceMicros, 'Minimo >= 9,99: '.self::euros($tiers->minimum->priceMicros));
        $this->assertGreaterThanOrEqual(12_490_000, $tiers->recommended->priceMicros, 'Recomendado >= 12,49: '.self::euros($tiers->recommended->priceMicros));
        $this->assertGreaterThanOrEqual(14_490_000, $tiers->premium->priceMicros, 'Premium >= 14,49: '.self::euros($tiers->premium->priceMicros));
        $this->assertGreaterThanOrEqual(1_900_000, $tiers->recommended->profitPerPrintHourMicros(), 'lucro/hora do Recomendado >= 1,90');

        // Peca leve e demorada: e a hora de impressora que manda.
        $this->assertSame(PriceTier::RULE_PER_HOUR, $tiers->recommended->rule);

        // Os numeros exatos, para a regressao.
        $this->assertSame(11_490_000, $tiers->minimum->priceMicros);
        $this->assertSame(14_490_000, $tiers->recommended->priceMicros);
        $this->assertSame(16_490_000, $tiers->premium->priceMicros);
    }

    /**
     * Variante 72 do catalogo (40 g, 2h08, 5,99 EUR): uma peca rapida nao pode
     * ser inflacionada pela regra por hora.
     */
    public function test_a_quick_catalog_part_is_not_inflated_by_the_hourly_rule(): void
    {
        $result = $this->part(grams: 40, minutes: 128);

        $recommended = $result->tiers->recommended->priceMicros;

        $this->assertLessThanOrEqual(6_990_000, $recommended, 'Recomendado da variante 72: '.self::euros($recommended).' (atual 5,99)');
        $this->assertNull($result->tiers->recommended->candidatesExVatMicros['min_job'], 'o lucro minimo por trabalho e so para orcamentos');
    }

    public function test_margins_are_measured_on_the_price_without_vat(): void
    {
        foreach ($this->realCase()->tiers->all() as $tier) {
            $exVat = Micros::divRound($tier->priceMicros * Rate::PER_UNIT, 12_300);

            $this->assertSame($exVat, $tier->priceExVatMicros);
            $this->assertSame($tier->priceMicros - $exVat, $tier->vatMicros());
            $this->assertSame($exVat - $tier->costMicros, $tier->profitMicros());
            $this->assertSame(Micros::divRound($tier->profitMicros() * Rate::PER_UNIT, $exVat), $tier->marginBp());
        }
    }

    public function test_prices_round_up_to_49_or_99(): void
    {
        $this->assertSame(490_000, Micros::ceilToEnding(1));
        $this->assertSame(11_490_000, Micros::ceilToEnding(11_000_000));
        $this->assertSame(11_490_000, Micros::ceilToEnding(11_490_000), 'idempotente');
        $this->assertSame(11_990_000, Micros::ceilToEnding(11_490_001), 'um micro acima sobe');
        $this->assertSame(11_990_000, Micros::ceilToEnding(11_500_000));
        $this->assertSame(11_990_000, Micros::nextEnding(11_490_000));

        foreach ([$this->realCase(), $this->part(grams: 40, minutes: 128), $this->part(grams: 5, minutes: 10)] as $result) {
            foreach ($result->tiers->all() as $tier) {
                $this->assertSame(49, intdiv($tier->priceMicros, Micros::PER_CENT) % 50, self::euros($tier->priceMicros));
            }
            $this->assertSame(49, intdiv($result->wholesalePriceMicros, Micros::PER_CENT) % 50);
        }
    }

    public function test_the_tiers_are_strictly_ordered_even_when_rounding_ties_them(): void
    {
        // Os tres niveis com os mesmos parametros: empatavam todos.
        foreach (PricingSettings::TIER_KEYS as $keys) {
            app(SettingService::class)->set($keys['per_hour'], 1_400_000);
            app(SettingService::class)->set($keys['min_job'], 500);
        }

        $tiers = $this->realCase()->tiers;

        $this->assertLessThan($tiers->recommended->priceMicros, $tiers->minimum->priceMicros);
        $this->assertLessThan($tiers->premium->priceMicros, $tiers->recommended->priceMicros);
        $this->assertSame(Micros::nextEnding($tiers->minimum->priceMicros), $tiers->recommended->priceMicros);
        $this->assertTrue($tiers->recommended->raisedForOrder);
        $this->assertTrue($tiers->premium->raisedForOrder);
        $this->assertFalse($tiers->minimum->raisedForOrder);
    }

    public function test_the_minimum_job_profit_and_the_design_split_across_twenty_parts(): void
    {
        // Uma mesa de 20 pecas pequenas: 100 g, 3h.
        $one = $this->part(grams: 100, minutes: 180, quote: true, designMinutes: 60, quantity: 20, mode: PricingInput::MODE_BATCH);

        $cost = $one->productionCostMicros;
        $recommended = $one->tiers->recommended;
        $premium = $one->tiers->premium;

        // 8 EUR do trabalho / 20 pecas = 0,40 EUR por peca.
        $this->assertSame($cost + 400_000, $recommended->candidatesExVatMicros['min_job']);
        // 60 min de design a 15 EUR/h = 15 EUR / 20 = 0,75 EUR por peca, so no Premium.
        $this->assertSame(750_000, $premium->designCostMicros);
        $this->assertSame(0, $recommended->designCostMicros);
        $this->assertSame($cost + 750_000, $premium->costMicros);
        $this->assertSame($cost + 750_000 + 500_000, $premium->candidatesExVatMicros['min_job'], '10 EUR / 20 + design');
    }

    public function test_without_the_new_inputs_retail_is_the_recommended_and_the_payload_keeps_its_fields(): void
    {
        $result = $this->part(grams: 50, minutes: 180);

        $this->assertSame($result->tiers->recommended->priceMicros, $result->retailPriceMicros);
        $this->assertSame($result->tiers->wholesalePriceMicros, $result->wholesalePriceMicros);
        $this->assertSame(['base' => 5, 'quote_minimum' => 0, 'plates' => 0, 'color_changes' => 0], $result->laborBreakdownMinutes);

        $array = $result->toArray();

        foreach ([
            'productionCostCents', 'wholesalePriceCents', 'retailPriceCents', 'wholesalePriceExVatCents',
            'retailPriceExVatCents', 'retailVatCents', 'wholesaleProfitCents', 'directProfitCents',
            'netDirectProfitCents', 'resellerProfitCents', 'wholesaleMarginBp', 'directMarginBp',
            'rawRetailPriceMicros', 'rawWholesalePriceMicros', 'laborMinutes', 'job',
        ] as $key) {
            $this->assertArrayHasKey($key, $array);
        }

        $this->assertSame(Micros::toCents($result->tiers->recommended->priceMicros), $array['retailPriceCents']);
        $this->assertSame(Micros::toCents($result->tiers->recommended->priceMicros), $array['tiers']['recommended']['priceCents']);
    }

    /**
     * O custo de uma variante nao pode mexer: e o que a Etsy e o travao do MCP
     * leem. As regras de orcamento so entram com quote = true.
     */
    public function test_quote_rules_never_touch_the_cost_of_a_catalog_part(): void
    {
        $catalog = $this->part(grams: 66, minutes: 277, plates: 2, colorChanges: 1);
        $plain = $this->part(grams: 66, minutes: 277);

        $this->assertSame($plain->productionCostMicros, $catalog->productionCostMicros);
        $this->assertSame(5, $catalog->laborMinutes);
    }

    public function test_wholesale_is_half_the_recommended_but_never_below_the_cost_floor(): void
    {
        $result = $this->realCase();
        $recommended = $result->tiers->recommended->priceMicros;
        $floor = Micros::applyBp(Micros::divRound($result->productionCostMicros * 12_300, Rate::PER_UNIT), 13_000);

        $this->assertSame(Micros::ceilToEnding(max(Micros::applyBp($recommended, 5_000), $floor)), $result->wholesalePriceMicros);
        $this->assertSame(PriceTiers::WHOLESALE_RULE_SHARE, $result->tiers->wholesaleRule);

        // Com a fracao a 10% e o chao que manda.
        app(SettingService::class)->set(PricingSettings::KEY_WHOLESALE_SHARE_OF_RECOMMENDED_BP, 1_000);
        $low = $this->realCase();

        $this->assertSame(PriceTiers::WHOLESALE_RULE_FLOOR, $low->tiers->wholesaleRule);
        $this->assertGreaterThanOrEqual($floor, $low->wholesalePriceMicros);
    }

    public function test_the_filament_reference_warns_above_fifteen_percent(): void
    {
        // 66 g a 16,99 EUR/kg = 1,12 EUR (com IVA, como o slicer). O Bambu
        // Studio disse 1,32: 15,05% de diferenca.
        $bambu = $this->part(grams: 66, minutes: 277, quote: true, referenceCents: 132);

        $this->assertSame(1_121_340, $bambu->filamentJobCostAsWrittenMicros);
        $this->assertSame(1_505, $bambu->filamentReferenceDiffBp());
        $this->assertTrue($bambu->filamentReferenceExceeded());

        $close = $this->part(grams: 66, minutes: 277, quote: true, referenceCents: 120);
        $this->assertFalse($close->filamentReferenceExceeded());

        $this->assertNull($this->realCase()->filamentReferenceDiffBp(), 'sem referencia, sem comparacao');
    }
}
