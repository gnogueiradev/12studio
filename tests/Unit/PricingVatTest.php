<?php

namespace Tests\Unit;

use App\Services\PricingCalculator;
use App\Services\PricingSettings;
use App\Services\SettingService;
use App\Support\Micros;
use App\Support\PricingInput;
use App\Support\PricingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O IVA na calculadora, em regime normal: o que se cobra entrega-se, o que se
 * paga nas compras deduz-se. A conta faz-se toda SEM IVA e o imposto so entra
 * no ultimo passo, para chegar ao preco da montra.
 *
 * A peca e a de referencia do PricingCalculatorTest — 50 g a 17 EUR/kg, 3 h na
 * A1 — para os dois ficheiros se lerem lado a lado.
 */
class PricingVatTest extends TestCase
{
    use RefreshDatabase;

    private PricingCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = app(PricingCalculator::class);
    }

    private function part(
        int $grams = 50,
        int $minutes = 180,
        int $vatRateBp = 2_300,
        int $packagingCents = 0,
        int $componentsCents = 0,
        int $quantity = 1,
        string $mode = PricingInput::MODE_PER_UNIT,
    ): PricingResult {
        return $this->calculator->calculate(new PricingInput(
            mode: $mode,
            weightGrams: $grams,
            minutes: $minutes,
            pricePerKgCents: 1_700,
            printerPowerWatts: 145,
            printerPurchasePriceCents: 40_000,
            printerLifetimeHours: 4_000,
            printerMaintenanceMicrosPerHour: 40_000,
            vatRateBp: $vatRateBp,
            packagingCostCents: $packagingCents,
            componentsCostCents: $componentsCents,
            quantity: $quantity,
        ));
    }

    private function costVat(int $bp): void
    {
        app(SettingService::class)->set(PricingSettings::KEY_COST_VAT_RATE_BP, $bp);
    }

    /**
     * A peca de referencia com tudo a 23%, parcela a parcela.
     *
     * Os precos da regra da margem ficam onde estavam (4,00 e 7,00 EUR): tirar
     * 23% ao que se compra e somar 23% ao que se vende anula-se, e o que sobra
     * — o IVA sobre a mao de obra — nao chega para saltar um degrau de 0,50
     * EUR. O Recomendado sai da hora de impressora (9,49 EUR), e dele 1,77
     * sao do Estado.
     */
    public function test_the_reference_part_with_vat_on_both_sides(): void
    {
        $result = $this->part();

        $this->assertSame(691_057, $result->filamentCostMicros, '0,85 EUR / 1,23');
        $this->assertSame(50_220, $result->electricityCostMicros, '0,06177 EUR / 1,23');
        $this->assertSame(243_902, $result->depreciationCostMicros, '0,30 EUR / 1,23');
        $this->assertSame(97_561, $result->maintenanceCostMicros, '0,12 EUR / 1,23');
        $this->assertSame(666_667, $result->laborCostMicros, 'o meu trabalho nao tem IVA para tirar');

        $this->assertSame(1_749_407, $result->baseProductionCostMicros);
        $this->assertSame(1_841_481, $result->productionCostMicros, 'subtotal / 0,95');

        // A regra da margem: os precos de sempre, 4,00 e 7,00 EUR.
        $this->assertSame(3_775_036, $result->rawWholesalePriceMicros, 'custo / 0,60 x 1,23');
        $this->assertSame(4_000_000, $result->marginWholesalePriceMicros);
        $this->assertSame(6_666_667, $result->rawRetailPriceMicros, 'revenda / 0,60');
        $this->assertSame(7_000_000, $result->marginRetailPriceMicros);

        // O Recomendado: 1,841481 + 3 h x 1,90 = 7,541481 sem IVA (> margem
        // 6,666667 / 1,23 = 5,420054) x 1,23 = 9,276022 -> 9,49.
        // A revenda: 50% de 9,49 = 4,745 -> 4,99.
        $this->assertSame(9_490_000, $result->retailPriceMicros);
        $this->assertSame(4_990_000, $result->wholesalePriceMicros);

        $this->assertSame(4_056_911, $result->wholesalePriceExVatMicros(), '4,99 EUR / 1,23');
        $this->assertSame(7_715_447, $result->retailPriceExVatMicros(), '9,49 EUR / 1,23');
        $this->assertSame(1_774_553, $result->retailVatMicros(), 'o que se entrega ao Estado');

        $this->assertSame(2_215_430, $result->wholesaleProfitMicros(), '4,056911 - 1,841481');
        $this->assertSame(5_461, $result->wholesaleMarginBp(), '54,61% do que fica depois do IVA');
        $this->assertSame(5_873_966, $result->directProfitMicros(), '7,715447 - 1,841481');
        $this->assertSame(7_613, $result->directMarginBp(), '76,13%');
    }

    /**
     * A ancora: com as duas taxas a zero a formula e a de antes, ao micro.
     * Sao os numeros do caso de referencia que o dono calculou a mao.
     */
    public function test_with_both_rates_at_zero_nothing_changes(): void
    {
        $this->costVat(0);

        $result = $this->part(vatRateBp: 0);

        $this->assertSame(850_000, $result->filamentCostMicros);
        $this->assertSame(61_770, $result->electricityCostMicros);
        $this->assertSame(2_103_618, $result->productionCostMicros);
        $this->assertSame(3_506_030, $result->rawWholesalePriceMicros);
        $this->assertSame(4_000_000, $result->marginWholesalePriceMicros);
        $this->assertSame(7_000_000, $result->marginRetailPriceMicros);

        // Os sugeridos sao os do PricingCalculatorTest: 7,99 e 4,49.
        $this->assertSame(7_990_000, $result->retailPriceMicros);
        $this->assertSame(4_490_000, $result->wholesalePriceMicros);
        $this->assertSame(4_490_000, $result->wholesalePriceExVatMicros(), 'sem IVA, o preco e todo meu');
        $this->assertSame(0, $result->retailVatMicros());
        $this->assertSame(5_315, $result->wholesaleMarginBp(), '(4,49 - 2,103618) / 4,49');
        $this->assertSame(7_367, $result->directMarginBp(), '(7,99 - 2,103618) / 7,99');
    }

    /**
     * Quem ja escreve os custos sem IVA poe a taxa dos custos a zero. Ai nada
     * se anula, e o preco sobe o IVA inteiro antes de arredondar.
     */
    public function test_costs_already_net_keep_their_full_value(): void
    {
        $this->costVat(0);

        $result = $this->part();

        $this->assertSame(850_000, $result->filamentCostMicros, 'nao ha IVA para tirar');
        $this->assertSame(2_103_618, $result->productionCostMicros);
        $this->assertSame(4_312_417, $result->rawWholesalePriceMicros, '3,506 EUR x 1,23');
        $this->assertSame(4_500_000, $result->marginWholesalePriceMicros);
        $this->assertSame(7_500_000, $result->marginRetailPriceMicros);

        // Recomendado: (2,103618 + 3 x 1,90) x 1,23 = 9,598 -> 9,99; revenda
        // 50% = 4,995 -> 5,49. Margem: (5,49/1,23 - 2,103618) / (5,49/1,23).
        $this->assertSame(9_990_000, $result->retailPriceMicros);
        $this->assertSame(5_490_000, $result->wholesalePriceMicros);
        $this->assertSame(5_287, $result->wholesaleMarginBp());
    }

    /**
     * O IVA da venda e do PRODUTO. O mesmo custo num produto a 6% da um preco
     * mais baixo — o cliente paga menos imposto, eu fico com o mesmo.
     */
    public function test_the_sale_rate_comes_with_the_part_and_moves_the_price(): void
    {
        $standard = $this->part();
        $reduced = $this->part(vatRateBp: 600);

        $this->assertSame($standard->productionCostMicros, $reduced->productionCostMicros, 'o custo nao depende de a quem vendo');
        $this->assertSame(3_253_283, $reduced->rawWholesalePriceMicros, 'custo / 0,60 x 1,06');
        $this->assertSame(3_500_000, $reduced->marginWholesalePriceMicros);
        $this->assertSame(6_000_000, $reduced->marginRetailPriceMicros);
        $this->assertLessThan($standard->retailPriceMicros, $reduced->retailPriceMicros, 'o Recomendado tambem desce');
        $this->assertSame(600, $reduced->vatRateBp);
    }

    /**
     * A embalagem e os componentes tambem se compram com IVA. Entram por
     * unidade, sem passar pela mesa, e por isso tem a sua propria divisao.
     */
    public function test_the_packaging_and_components_lose_their_vat_too(): void
    {
        $result = $this->part(packagingCents: 20, componentsCents: 30);

        $this->assertSame(162_602, $result->packagingCostMicros, '0,20 EUR / 1,23');
        $this->assertSame(243_902, $result->componentsCostMicros, '0,30 EUR / 1,23');
    }

    /** A regra do painel detalhado nao muda: o subtotal e a soma do que se ve. */
    public function test_the_subtotal_is_still_the_sum_of_the_lines(): void
    {
        foreach ([[50, 180, 1], [45, 150, 1], [120, 400, 7], [8, 25, 3]] as [$grams, $minutes, $quantity]) {
            $result = $this->part(
                grams: $grams,
                minutes: $minutes,
                packagingCents: 12,
                componentsCents: 35,
                quantity: $quantity,
                mode: PricingInput::MODE_BATCH,
            );

            $this->assertSame(
                $result->filamentCostMicros
                    + $result->electricityCostMicros
                    + $result->depreciationCostMicros
                    + $result->maintenanceCostMicros
                    + $result->laborCostMicros
                    + $result->packagingCostMicros
                    + $result->componentsCostMicros,
                $result->baseProductionCostMicros,
                "a conta nao fecha com {$grams} g / {$minutes} min / {$quantity} un",
            );
        }
    }

    /**
     * A margem pedida e sobre o que FICA depois do IVA. Era isto que a formula
     * sem IVA nao garantia: os 40% saiam de um preco que nao era todo meu.
     */
    public function test_my_margin_after_vat_is_never_below_the_target(): void
    {
        for ($minutes = 10; $minutes <= 3_000; $minutes += 37) {
            $result = $this->part(grams: (int) ($minutes / 3), minutes: $minutes);

            $this->assertGreaterThanOrEqual(
                $result->targetWholesaleMarginBp,
                $result->wholesaleMarginBp(),
                "a minha margem ficou abaixo do alvo aos {$minutes} min",
            );
        }
    }

    /**
     * A margem do revendedor e um racio entre dois precos com a mesma taxa: e
     * igual com ou sem IVA, e continua garantida pelo arredondamento para cima.
     */
    public function test_the_reseller_margin_is_untouched_by_vat(): void
    {
        // Sobre os precos da REGRA DA MARGEM, que e quem promete a margem do
        // revendedor: 4,00 -> 7,00, com ou sem IVA.
        $reseller = fn (PricingResult $r): int => Micros::divRound(
            ($r->marginRetailPriceMicros - $r->marginWholesalePriceMicros) * 10_000,
            $r->marginRetailPriceMicros,
        );

        $this->assertSame(4_286, $reseller($this->part()));

        for ($minutes = 10; $minutes <= 3_000; $minutes += 37) {
            $result = $this->part(grams: (int) ($minutes / 3), minutes: $minutes);

            $this->assertGreaterThanOrEqual(
                $result->targetResellerMarginBp,
                $reseller($result),
                "o revendedor ficou abaixo do alvo aos {$minutes} min",
            );
        }

        // Com os sugeridos (9,49 e 4,99) e o mesmo racio dos precos com IVA.
        $this->assertSame(4_742, $this->part()->resellerMarginBp(), '(9,49 - 4,99) / 9,49');
    }

    /** Mas o que ele ganha em euros, depois de entregar o IVA dele, nao. */
    public function test_the_reseller_profit_is_what_is_left_after_vat(): void
    {
        $result = $this->part();

        // 9,49 / 1,23 - 4,99 / 1,23.
        $this->assertSame(7_715_447 - 4_056_911, $result->resellerProfitMicros());
    }

    /**
     * A comissao do canal incide sobre o que o cliente paga — IVA incluido, que
     * e como os marketplaces a cobram — e sai do meu lucro ja sem IVA.
     */
    public function test_the_channel_fee_is_charged_on_the_price_the_customer_pays(): void
    {
        $settings = app(SettingService::class);
        $settings->set(PricingSettings::KEY_SALES_CHANNEL_FIXED_FEE_CENTS, 35);
        $settings->set(PricingSettings::KEY_SALES_CHANNEL_PERCENTAGE_FEE_BP, 1_000);

        $result = $this->part();

        $this->assertSame(1_299_000, $result->channelFeeMicros, '0,35 EUR + 10% de 9,49 EUR (o Recomendado)');
        $this->assertSame(5_873_966 - 1_299_000, $result->netDirectProfitMicros());
    }

    /**
     * Um preco qualquer — o que alguem escreveu a mao numa variante — visto sem
     * o IVA deste calculo. E o que o travao do MCP e a ficha da variante
     * comparam com o custo.
     */
    public function test_any_price_can_be_read_without_vat(): void
    {
        $result = $this->part();

        $this->assertSame(10_000_000, $result->exVat(12_300_000));
        $this->assertSame(5_000_000, $this->part(vatRateBp: 0)->exVat(5_000_000));
    }

    /** O que viaja para a pagina: as taxas e os precos sem IVA, em centimos. */
    public function test_the_payload_carries_the_rates_and_the_prices_without_vat(): void
    {
        $payload = $this->part()->toArray();

        $this->assertSame(2_300, $payload['vatRateBp']);
        $this->assertSame(2_300, $payload['costVatRateBp']);
        $this->assertSame(406, $payload['wholesalePriceExVatCents'], '4,99 / 1,23');
        $this->assertSame(772, $payload['retailPriceExVatCents'], '9,49 / 1,23');
        $this->assertSame(177, $payload['retailVatCents']);
        $this->assertSame(949, $payload['retailPriceExVatCents'] + $payload['retailVatCents'], 'as duas partes fecham o preco');
        $this->assertSame(Micros::toCents(2_215_430), $payload['wholesaleProfitCents']);
    }

    /**
     * Uma taxa negativa escrita a mao na tabela `settings` nao passa por
     * validacao nenhuma, e nao pode inflacionar os custos.
     */
    public function test_a_negative_cost_rate_is_treated_as_zero(): void
    {
        $this->costVat(-500);

        $this->assertSame(850_000, $this->part()->filamentCostMicros);
    }
}
