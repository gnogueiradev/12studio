<?php

namespace Tests\Unit;

use App\Services\PricingCalculator;
use App\Services\PricingSettings;
use App\Services\SettingService;
use App\Support\Micros;
use App\Support\PricingInput;
use App\Support\PricingResult;
use App\Support\Rate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private PricingCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = app(PricingCalculator::class);
    }

    /**
     * Uma peca em modo unitario, com os parametros por omissao: 17 EUR/kg e uma
     * Bambu Lab A1 (145 W, 400 EUR, 4000 h de vida, 0,04 EUR/h de manutencao).
     */
    private function part(
        int $grams,
        int $minutes,
        int $packagingCents = 0,
        int $componentsCents = 0,
        ?int $activeLaborMinutes = null,
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
            packagingCostCents: $packagingCents,
            componentsCostCents: $componentsCents,
            activeLaborMinutes: $activeLaborMinutes,
            quantity: $quantity,
        ));
    }

    /**
     * O CASO DE REFERENCIA. E o preco que o dono calculou a mao para uma peca
     * real — 50 g de filamento a 17 EUR/kg, 3 horas na A1 — e e ele que define
     * o comportamento oficial da calculadora. Cada decisao de arredondamento no
     * App\Support\Micros existe para reproduzir estes numeros exatamente; se
     * este teste ficar vermelho, e a formula que mudou, nao o teste.
     *
     * Nao ha um unico arredondamento perdido no caminho: as unicas divisoes que
     * nao dao inteiro exato sao a mao de obra (5/60 de hora) e as duas do
     * gross-up, e essas o divRound resolve ao micro.
     */
    public function test_the_reference_part_reproduces_the_hand_calculated_price(): void
    {
        $result = $this->part(grams: 50, minutes: 180);

        $this->assertSame(850_000, $result->filamentCostMicros, '50 g x 0,017 EUR/g');
        $this->assertSame(61_770, $result->electricityCostMicros, '3 h x 145 W x 0,1420 EUR/kWh');
        $this->assertSame(300_000, $result->depreciationCostMicros, '3 h x (400 EUR / 4000 h)');
        $this->assertSame(120_000, $result->maintenanceCostMicros, '3 h x 0,04 EUR/h');
        $this->assertSame(666_667, $result->laborCostMicros, '5 min x 8 EUR/h');
        $this->assertSame(0, $result->packagingCostMicros);
        $this->assertSame(0, $result->componentsCostMicros);

        $this->assertSame(1_998_437, $result->baseProductionCostMicros, 'a soma das sete parcelas');
        $this->assertSame(105_181, $result->failureCostMicros(), 'o que o risco acrescentou');
        $this->assertSame(2_103_618, $result->productionCostMicros, 'subtotal / 0,95');

        $this->assertSame(3_506_030, $result->rawWholesalePriceMicros, 'custo / 0,60');
        $this->assertSame(4_000_000, $result->wholesalePriceMicros, 'para cima, ao proximo 0,50 EUR');

        $this->assertSame(6_666_667, $result->rawRetailPriceMicros, 'revenda / 0,60');
        $this->assertSame(7_000_000, $result->retailPriceMicros, 'para cima, ao proximo 0,50 EUR');

        // O meu lucro, nas duas vendas.
        $this->assertSame(1_896_382, $result->wholesaleProfitMicros());
        $this->assertSame(4_741, $result->wholesaleMarginBp(), '47,41% — acima dos 40% pedidos, por causa do arredondamento');
        $this->assertSame(4_896_382, $result->directProfitMicros());
        $this->assertSame(6_995, $result->directMarginBp(), '69,95%');

        // O lucro de quem revende: compra a 4, vende a 7.
        $this->assertSame(3_000_000, $result->resellerProfitMicros());
        $this->assertSame(4_286, $result->resellerMarginBp(), '42,86%');
        $this->assertSame(7_500, $result->resellerMarkupBp(), '75% sobre o que pagou');
    }

    /**
     * A guarda de regressao mais importante das duas mudancas estruturais.
     *
     * Somar 5% (custo x 1,05) recupera MENOS do que se perdeu: a peca falhada
     * tambem gastou filamento, luz e horas de maquina. Com 100 pecas e 5
     * falhadas, sao 95 a pagar o custo de 100 — logo /0,95.
     */
    public function test_the_failure_rate_divides_the_cost_it_does_not_multiply_it(): void
    {
        $result = $this->part(grams: 50, minutes: 180);

        $naive = Micros::applyBp($result->baseProductionCostMicros, Rate::PER_UNIT + $result->failureRateBp);

        $this->assertNotSame($naive, $result->productionCostMicros);
        $this->assertGreaterThan($naive, $result->productionCostMicros, 'dividir recupera mais do que somar');

        // E o inverso fecha: custo real x (1 - taxa) volta ao subtotal.
        $this->assertEqualsWithDelta(
            $result->baseProductionCostMicros,
            Micros::applyBp($result->productionCostMicros, Rate::PER_UNIT - $result->failureRateBp),
            1,
            'a divisao e reversivel ao micro',
        );
    }

    /**
     * O subtotal tem de ser exatamente a soma das linhas que o painel mostra
     * por cima dele. Um subtotal que nao bate certo com as parcelas faz duvidar
     * do resto do ecra — e foi por isso que a soma passou a ser feita sobre as
     * parcelas JA divididas pelo lote, e nao sobre o total do trabalho.
     */
    public function test_the_subtotal_is_exactly_the_sum_of_the_lines_shown_above_it(): void
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
     * O preco ao cliente arredonda SEMPRE para cima. Ja arredondou ao mais
     * proximo, e uma descida comia a margem que se acabou de pedir — a ponto de
     * ter sido preciso uma rede de seguranca para a apanhar.
     */
    public function test_the_retail_price_is_always_rounded_up_never_down(): void
    {
        for ($minutes = 10; $minutes <= 3_000; $minutes += 37) {
            $result = $this->part(grams: (int) ($minutes / 3), minutes: $minutes);

            $this->assertGreaterThanOrEqual(
                $result->rawRetailPriceMicros,
                $result->retailPriceMicros,
                "o preco desceu abaixo do bruto aos {$minutes} min",
            );

            $step = match (true) {
                $result->rawRetailPriceMicros < 20 * Micros::PER_EURO => 500_000,
                $result->rawRetailPriceMicros <= 50 * Micros::PER_EURO => 1_000_000,
                default => 5_000_000,
            };

            $this->assertSame(0, $result->retailPriceMicros % $step, "nao e um preco comercial aos {$minutes} min");
        }
    }

    /**
     * A prova de que a rede de seguranca apagada era mesmo redundante.
     *
     * Antes, o multiplicador minimo do revendedor existia porque o
     * arredondamento tanto subia como descia. Com o `ceil`, o preco ao cliente
     * nunca fica abaixo de revenda / (1 - margem), e por isso a margem do
     * revendedor e um chao garantido por construcao.
     */
    public function test_the_reseller_margin_is_never_below_the_target(): void
    {
        for ($minutes = 10; $minutes <= 3_000; $minutes += 37) {
            $result = $this->part(grams: (int) ($minutes / 3), minutes: $minutes);

            $this->assertGreaterThanOrEqual(
                $result->targetResellerMarginBp,
                $result->resellerMarginBp(),
                "o revendedor ficou abaixo do alvo aos {$minutes} min",
            );
        }
    }

    /** O mesmo do lado de ca: a minha margem tambem nunca fica abaixo do alvo. */
    public function test_my_wholesale_margin_is_never_below_the_target(): void
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
     * A tarifa da luz nao cabe num centimo, e o custo de energia de uma peca
     * tambem nao. Guardar isto em centimos fazia 0,1420 EUR/kWh virar 0,14 e o
     * custo de energia sair 1,4% ao lado — num numero que se multiplica pelas
     * horas todas do ano.
     */
    public function test_the_electricity_cost_keeps_its_sub_cent_precision(): void
    {
        $result = $this->part(grams: 50, minutes: 180);

        $this->assertSame(61_770, $result->electricityCostMicros);
        $this->assertNotSame(0, $result->electricityCostMicros % Micros::PER_CENT, 'nao e um numero redondo de centimos');
        $this->assertSame(6, Micros::toCents($result->electricityCostMicros), 'e mostra-se como 0,06 EUR');
    }

    /**
     * A vida util e um DIVISOR. O formulario poe min:1, mas uma definicao
     * escrita a mao por fora nao passa por validacao nenhuma — e um erro de
     * digitacao nao pode derrubar o backoffice inteiro.
     */
    public function test_a_printer_with_no_lifetime_does_not_divide_by_zero(): void
    {
        $result = $this->calculator->calculate(new PricingInput(
            mode: PricingInput::MODE_PER_UNIT,
            weightGrams: 50,
            minutes: 180,
            pricePerKgCents: 1_700,
            printerPowerWatts: 145,
            printerPurchasePriceCents: 40_000,
            printerLifetimeHours: 0,
            printerMaintenanceMicrosPerHour: 40_000,
        ));

        $this->assertSame(0, $result->depreciationCostMicros, 'sem vida util nao ha amortizacao');
        $this->assertGreaterThan(0, $result->productionCostMicros);
    }

    /**
     * O chao protege as pecas pequenas, e e ELE que decide o preco delas — nao
     * a margem. Uma peca de 5 g e 10 minutos custa cerca de 0,82 EUR a
     * produzir, o que a 40% de margem dava 1,37 EUR de revenda.
     */
    public function test_a_tiny_fast_part_is_saved_by_the_wholesale_floor(): void
    {
        $result = $this->part(grams: 5, minutes: 10);

        $this->assertLessThan(900_000, $result->productionCostMicros);
        $this->assertSame(1_500_000, $result->rawWholesalePriceMicros, 'o chao ganhou a margem');
        $this->assertSame(1_500_000, $result->wholesalePriceMicros, 'e ja e um degrau exato de 0,50');
    }

    /**
     * O tempo ativo e por peca, e uma peca que leve mais acabamento tem de
     * poder dize-lo. Null quer dizer "usa a definicao global" — e diferente de
     * zero, que quer dizer "esta peca nao leva trabalho nenhum".
     */
    public function test_a_part_can_override_the_active_labor_minutes(): void
    {
        $standard = $this->part(grams: 50, minutes: 180);
        $fiddly = $this->part(grams: 50, minutes: 180, activeLaborMinutes: 20);
        $none = $this->part(grams: 50, minutes: 180, activeLaborMinutes: 0);

        $this->assertSame(666_667, $standard->laborCostMicros, 'null cai nos 5 min globais');
        $this->assertSame(2_666_667, $fiddly->laborCostMicros, '20 min x 8 EUR/h');
        $this->assertSame(0, $none->laborCostMicros, 'zero e mesmo zero, nao "por omissao"');

        $this->assertGreaterThan($standard->wholesalePriceMicros, $fiddly->wholesalePriceMicros);
        $this->assertLessThan($standard->wholesalePriceMicros, $none->wholesalePriceMicros);
    }

    /**
     * A embalagem e os componentes PAGAM o risco de falhas, ao contrario do que
     * acontecia na formula antiga (onde os "custos extra" entravam depois da
     * reserva). E a leitura certa: quando uma impressao falha, o saco e o iman
     * que ja la estavam perdem-se com ela.
     */
    public function test_the_packaging_and_components_pay_the_failure_gross_up(): void
    {
        $bare = $this->part(grams: 50, minutes: 180);
        $kitted = $this->part(grams: 50, minutes: 180, packagingCents: 20, componentsCents: 30);

        $addedToBase = $kitted->baseProductionCostMicros - $bare->baseProductionCostMicros;
        $addedToCost = $kitted->productionCostMicros - $bare->productionCostMicros;

        $this->assertSame(500_000, $addedToBase, '0,50 EUR de extras');
        $this->assertGreaterThan($addedToBase, $addedToCost, 'e tambem eles se perdem quando a peca falha');
    }

    /**
     * O tempo de maquina e a segunda maior parcela e a unica que cresce com as
     * horas. Metade do tempo tem de dar um preco visivelmente mais baixo — foi
     * para isto que o tempo passou a ser input da formula.
     */
    public function test_halving_the_print_time_lowers_the_price(): void
    {
        $long = $this->part(grams: 50, minutes: 360);
        $short = $this->part(grams: 50, minutes: 180);

        $this->assertSame($long->filamentCostMicros, $short->filamentCostMicros, 'o filamento e o mesmo');
        $this->assertSame($long->electricityCostMicros, 2 * $short->electricityCostMicros);
        $this->assertSame($long->depreciationCostMicros, 2 * $short->depreciationCostMicros);
        $this->assertLessThan($long->wholesalePriceMicros, $short->wholesalePriceMicros);
    }

    /** As tres faixas comerciais, cada uma com o seu degrau. */
    public function test_the_retail_price_uses_the_euro_band_between_twenty_and_fifty(): void
    {
        $result = $this->part(grams: 500, minutes: 600);

        $this->assertSame(31_666_667, $result->rawRetailPriceMicros);
        $this->assertSame(32_000_000, $result->retailPriceMicros, 'entre 20 e 50 EUR sobe ao euro');
    }

    public function test_the_retail_price_uses_the_five_euro_band_above_fifty(): void
    {
        $result = $this->part(grams: 1_000, minutes: 1_200);

        $this->assertSame(61_666_667, $result->rawRetailPriceMicros);
        $this->assertSame(65_000_000, $result->retailPriceMicros, 'acima de 50 EUR sobe aos 5 EUR');
    }

    /**
     * As comissoes do canal ficam FORA do custo industrial: nao custam nada
     * produzir, e metidas no custo contaminavam o preco de revenda de pecas
     * vendidas por outros canais. O preco nao se mexe — o que se mexe e o que
     * sobra.
     */
    public function test_the_channel_fees_cut_the_net_profit_without_moving_the_price(): void
    {
        $free = $this->part(grams: 50, minutes: 180);

        $settings = app(SettingService::class);
        $settings->set(PricingSettings::KEY_SALES_CHANNEL_FIXED_FEE_CENTS, 35);
        $settings->set(PricingSettings::KEY_SALES_CHANNEL_PERCENTAGE_FEE_BP, 1_000);

        $marketplace = $this->part(grams: 50, minutes: 180);

        $this->assertSame($free->retailPriceMicros, $marketplace->retailPriceMicros, 'o preco publico e o mesmo');
        $this->assertSame($free->productionCostMicros, $marketplace->productionCostMicros, 'e o custo tambem');

        // 0,35 EUR fixos + 10% de 7,00 EUR.
        $this->assertSame(1_050_000, $marketplace->channelFeeMicros);
        $this->assertSame(
            $marketplace->directProfitMicros() - 1_050_000,
            $marketplace->netDirectProfitMicros(),
        );
    }

    public function test_with_no_channel_fees_the_net_profit_equals_the_direct_profit(): void
    {
        $result = $this->part(grams: 50, minutes: 180);

        $this->assertSame(0, $result->channelFeeMicros);
        $this->assertSame($result->directProfitMicros(), $result->netDirectProfitMicros());
        $this->assertSame($result->directMarginBp(), $result->netDirectMarginBp());
    }

    /**
     * Uma margem a 100% era uma divisao por zero. O formulario poe um tecto de
     * 95%, mas uma chave escrita a mao na tabela `settings` nao passa por
     * validacao nenhuma.
     */
    public function test_an_impossible_margin_is_clamped_instead_of_dividing_by_zero(): void
    {
        app(SettingService::class)->set(PricingSettings::KEY_TARGET_WHOLESALE_MARGIN_BP, 10_000);

        $result = $this->part(grams: 50, minutes: 180);

        $this->assertSame(9_999, $result->targetWholesaleMarginBp);
        $this->assertGreaterThan(0, $result->wholesalePriceMicros);
    }

    /**
     * Uma definicao guardada nao pode ficar so no ficheiro de config: mudar a
     * tarifa da luz tem de mexer no preco, ou a area de definicoes e decorativa.
     */
    public function test_a_saved_setting_really_moves_the_price(): void
    {
        $before = $this->part(grams: 50, minutes: 180);

        // Tarifa a dobrar.
        app(SettingService::class)->set(PricingSettings::KEY_ELECTRICITY_PRICE_MICROS_PER_KWH, 284_000);

        $after = $this->part(grams: 50, minutes: 180);

        $this->assertSame(123_540, $after->electricityCostMicros, 'o dobro de 61_770');
        $this->assertGreaterThan($before->productionCostMicros, $after->productionCostMicros);
    }
}
