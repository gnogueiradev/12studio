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
     * Uma peca em modo unitario, com os parametros por omissao: 17 EUR/kg e a
     * Bambu Lab A1 a 0,50 EUR/h.
     */
    private function part(int $grams, int $minutes, int $extraCents = 0, int $quantity = 1): PricingResult
    {
        return $this->calculator->calculate(new PricingInput(
            mode: PricingInput::MODE_PER_UNIT,
            weightGrams: $grams,
            minutes: $minutes,
            pricePerKgCents: 1_700,
            hourlyRateCents: 50,
            extraCostCents: $extraCents,
            quantity: $quantity,
        ));
    }

    /**
     * O CASO DE REFERENCIA. E o preco que o dono calculou a mao para uma peca
     * real — 17 EUR/kg, 32 g, 1h30 na A1 — e e ele que define o comportamento
     * oficial da calculadora. Cada decisao de arredondamento no App\Support\
     * Micros existe para reproduzir estes numeros exatamente; se este teste
     * ficar vermelho, e a formula que mudou, nao o teste.
     */
    public function test_the_reference_part_prices_at_three_fifty_of_resale_and_six_euros_of_retail(): void
    {
        $result = $this->part(grams: 32, minutes: 90);

        $this->assertSame(544_000, $result->filamentCostMicros, '32 g x 0,017 EUR/g = 0,544 EUR');
        $this->assertSame(750_000, $result->machineCostMicros, '1,5 h x 0,50 EUR/h = 0,75 EUR');
        $this->assertSame(250_000, $result->handlingCostMicros, 'ate 50 g = 0,25 EUR');
        $this->assertSame(103_520, $result->failureReserveMicros, '(0,544 + 0,75) x 8% = 0,10352 EUR');
        $this->assertSame(0, $result->extraCostMicros);
        $this->assertSame(1_647_520, $result->productionCostMicros, 'custo real = 1,64752 EUR');

        $this->assertSame(20_000, $result->resaleMultiplierBp, 'custo <= 2 EUR -> 2,00x');
        $this->assertSame(3_295_040, $result->rawResalePriceMicros, '1,64752 x 2 = 3,29504 EUR');
        $this->assertSame(3_500_000, $result->resalePriceMicros, 'para cima aos 0,50 -> 3,50 EUR');

        $this->assertSame(6_125_000, $result->rawRetailPriceMicros, '3,50 x 1,75 = 6,125 EUR');
        $this->assertSame(6_000_000, $result->retailPriceMicros, 'ao 0,50 mais proximo -> 6,00 EUR');
        $this->assertFalse($result->retailBumped, '6,00 >= 3,50 x 1,60 = 5,60');

        $this->assertSame(165, Micros::toCents($result->productionCostMicros));
        $this->assertSame(350, Micros::toCents($result->resalePriceMicros));
        $this->assertSame(600, Micros::toCents($result->retailPriceMicros));
    }

    /**
     * O irmao do teste de referencia, e o que apanha um toCents() prematuro.
     * Se alguem arredondar o filamento a 0,54 EUR "porque e o que se mostra", o
     * custo cai para 1,64 e o preco de revenda continua a dar 3,50 — o erro so
     * aparece meses depois, numa peca onde o degrau calha ao contrario.
     */
    public function test_no_intermediate_value_is_rounded_down_to_the_cent(): void
    {
        $result = $this->part(grams: 32, minutes: 90);

        $this->assertNotSame(0, $result->filamentCostMicros % Micros::PER_CENT, '0,544 nao e um numero redondo de centimos');
        $this->assertSame(1_647_520, $result->productionCostMicros);
        $this->assertSame(3_295_040, $result->rawResalePriceMicros);
    }

    /**
     * A razao de ser desta versao inteira. Mesmo filamento, mesmo peso, metade
     * do tempo: o preco TEM de descer. Na formula antiga, baseada na gramagem,
     * estas duas pecas custavam o mesmo.
     */
    public function test_halving_the_print_time_drops_the_resale_price_to_two_fifty(): void
    {
        $fast = $this->part(grams: 32, minutes: 30);
        $slow = $this->part(grams: 32, minutes: 90);

        $this->assertSame($fast->filamentCostMicros, $slow->filamentCostMicros, 'o material e o mesmo');

        $this->assertSame(250_000, $fast->machineCostMicros, '0,5 h x 0,50 EUR/h');
        $this->assertSame(63_520, $fast->failureReserveMicros);
        $this->assertSame(1_107_520, $fast->productionCostMicros, 'custo real = 1,10752 EUR');
        $this->assertSame(2_215_040, $fast->rawResalePriceMicros);

        $this->assertSame(250, Micros::toCents($fast->resalePriceMicros), '30 min -> 2,50 EUR');
        $this->assertSame(350, Micros::toCents($slow->resalePriceMicros), '1h30 -> 3,50 EUR');
    }

    /**
     * Um ima ou uma caixa entram DEPOIS da impressao. Uma impressao falhada nao
     * os consome, logo nao pagam seguro de falha — so filamento e maquina
     * pagam.
     */
    public function test_the_extra_cost_does_not_pay_the_failure_reserve(): void
    {
        $bare = $this->part(grams: 32, minutes: 90);
        $withExtras = $this->part(grams: 32, minutes: 90, extraCents: 65);

        $this->assertSame($bare->failureReserveMicros, $withExtras->failureReserveMicros);
        $this->assertSame(650_000, $withExtras->extraCostMicros);
        $this->assertSame(
            $bare->productionCostMicros + 650_000,
            $withExtras->productionCostMicros,
            'os extras entram a cru, sem reserva por cima',
        );
    }

    public function test_the_handling_cost_follows_the_weight_tier(): void
    {
        $this->assertSame(250_000, $this->part(grams: 50, minutes: 10)->handlingCostMicros);
        $this->assertSame(350_000, $this->part(grams: 51, minutes: 10)->handlingCostMicros);
        $this->assertSame(350_000, $this->part(grams: 150, minutes: 10)->handlingCostMicros);
        $this->assertSame(500_000, $this->part(grams: 300, minutes: 10)->handlingCostMicros);
        $this->assertSame(750_000, $this->part(grams: 500, minutes: 10)->handlingCostMicros);
        $this->assertSame(1_000_000, $this->part(grams: 501, minutes: 10)->handlingCostMicros);
    }

    /**
     * Margem progressiva: quanto mais barata a peca, maior a margem relativa.
     * Sem isto, um porta-chaves vendia-se com um lucro que nao paga o saco.
     */
    public function test_the_resale_multiplier_falls_as_the_production_cost_rises(): void
    {
        // custo 1,64752 EUR
        $this->assertSame(20_000, $this->part(grams: 32, minutes: 90)->resaleMultiplierBp);
        // custo 4,886 EUR
        $this->assertSame(19_000, $this->part(grams: 100, minutes: 300)->resaleMultiplierBp);
        // custo 7,772 EUR
        $this->assertSame(18_000, $this->part(grams: 200, minutes: 400)->resaleMultiplierBp);
        // custo 14,108 EUR
        $this->assertSame(17_000, $this->part(grams: 300, minutes: 900)->resaleMultiplierBp);
    }

    /**
     * O preco de revenda vive numa lista comercial (1,50 / 2,00 / 2,50 ...) e
     * arredonda sempre para CIMA. Para baixo, o multiplicador de margem que
     * acabamos de aplicar era parcialmente devolvido ao cliente.
     */
    public function test_the_resale_price_is_always_rounded_up_to_the_next_fifty_cents(): void
    {
        foreach ([[32, 90, 350], [32, 30, 250], [20, 300, 650]] as [$grams, $minutes, $expected]) {
            $resale = Micros::toCents($this->part($grams, $minutes)->resalePriceMicros);

            $this->assertSame($expected, $resale);
            $this->assertSame(0, $resale % 50, "{$resale} nao e um degrau de 0,50 EUR");
        }
    }

    /**
     * Uma peca minuscula e rapida da um preco bruto abaixo do chao. O chao
     * ganha — nem que seja so para o saco, a etiqueta e o tempo de atender.
     */
    public function test_a_cheap_part_never_sells_below_the_minimum_resale_price(): void
    {
        $tiny = $this->part(grams: 1, minutes: 1);

        $this->assertLessThan(1_500_000, Micros::applyBp($tiny->productionCostMicros, 20_000));
        $this->assertSame(1_500_000, $tiny->rawResalePriceMicros, 'o chao de 1,50 EUR');
        $this->assertSame(150, Micros::toCents($tiny->resalePriceMicros));
    }

    public function test_the_retail_price_rounds_to_the_euro_between_twenty_and_fifty(): void
    {
        $result = $this->part(grams: 250, minutes: 900);

        $this->assertGreaterThanOrEqual(20 * Micros::PER_EURO, $result->rawRetailPriceMicros);
        $this->assertLessThanOrEqual(50 * Micros::PER_EURO, $result->rawRetailPriceMicros);
        $this->assertSame(0, Micros::toCents($result->retailPriceMicros) % 100, 'euro redondo');
    }

    public function test_the_retail_price_rounds_to_five_euros_above_fifty(): void
    {
        $result = $this->part(grams: 900, minutes: 2_400);

        $this->assertGreaterThan(50 * Micros::PER_EURO, $result->rawRetailPriceMicros);
        $this->assertSame(0, Micros::toCents($result->retailPriceMicros) % 500, 'multiplo de 5 EUR');
    }

    /**
     * O arredondamento comercial do preco ao cliente tanto sobe como DESCE, e
     * uma descida pode deixar o revendedor abaixo do markup que justifica ele
     * pegar no produto. Quando isso acontece, sobe-se — nunca se baixa o preco
     * de revenda para compensar.
     *
     * Com o minimo apertado a 1,72x, o caso de referencia cai na rede: 3,50 x
     * 1,75 = 6,125 arredonda para 6,00, e 6,00 < 3,50 x 1,72 = 6,02.
     */
    public function test_the_retail_price_is_bumped_when_it_would_leave_the_reseller_under_the_minimum(): void
    {
        app(SettingService::class)->set(PricingSettings::KEY_MINIMUM_RETAIL_MULTIPLIER_BP, 17_200);

        $result = app(PricingCalculator::class)->calculate(new PricingInput(
            mode: PricingInput::MODE_PER_UNIT,
            weightGrams: 32,
            minutes: 90,
            pricePerKgCents: 1_700,
            hourlyRateCents: 50,
        ));

        $this->assertTrue($result->retailBumped);
        $this->assertSame(6_020_000, $result->minimumRetailPriceMicros);
        $this->assertSame(6_125_000, $result->rawRetailPriceMicros);
        $this->assertSame(650, Micros::toCents($result->retailPriceMicros), 'sobe ao proximo degrau: 6,50 EUR');
        $this->assertGreaterThanOrEqual($result->minimumRetailPriceMicros, $result->retailPriceMicros);
    }

    /**
     * Subir para proteger a margem nao pode produzir um preco fora da lista
     * comercial — 6,02 EUR de minimo tem de virar 6,50 e nao 6,02. Varre-se a
     * gama toda porque o degrau valido muda de faixa para faixa.
     */
    public function test_the_bumped_price_is_still_a_valid_commercial_price(): void
    {
        // Multiplicador minimo colado ao normal: forca a rede a disparar quase
        // sempre, que e o unico regime onde este invariante e testavel.
        app(SettingService::class)->set(PricingSettings::KEY_MINIMUM_RETAIL_MULTIPLIER_BP, 17_499);

        $bumps = 0;

        for ($minutes = 10; $minutes <= 3_000; $minutes += 10) {
            $result = $this->part(grams: 40, minutes: $minutes);

            if (! $result->retailBumped) {
                continue;
            }

            $bumps++;
            $cents = Micros::toCents($result->retailPriceMicros);
            $step = match (true) {
                $cents < 2_000 => 50,
                $cents <= 5_000 => 100,
                default => 500,
            };

            $this->assertSame(0, $cents % $step, "{$cents} nao e um preco comercial valido");
            $this->assertGreaterThanOrEqual($result->minimumRetailPriceMicros, $result->retailPriceMicros);
        }

        $this->assertGreaterThan(0, $bumps, 'a varredura tem de acionar a protecao pelo menos uma vez');
    }

    /**
     * Com os multiplicadores por omissao a rede NUNCA dispara, e isso e uma
     * propriedade e nao um acaso: a folga entre 1,75x e 1,60x e 0,15 x revenda,
     * e a descida maxima de um arredondamento e metade do degrau da sua faixa —
     * a folga cresce com o preco, o degrau nao.
     *
     * Vale a pena fixar isto: quer dizer que o preco recomendado ao cliente sai
     * do arredondamento comercial limpo, sem ninguem lhe mexer por cima. Se um
     * dia este teste ficar vermelho, alguem desequilibrou os dois
     * multiplicadores por omissao no config/pricing.php.
     */
    public function test_the_default_multipliers_never_need_the_safety_net(): void
    {
        for ($minutes = 10; $minutes <= 3_000; $minutes += 10) {
            foreach ([5, 40, 200, 600] as $grams) {
                $this->assertFalse(
                    $this->part($grams, $minutes)->retailBumped,
                    "{$grams} g / {$minutes} min acionou a protecao com os valores por omissao",
                );
            }
        }
    }

    /**
     * As margens sao a leitura que interessa ao dono, e a definicao oficial do
     * plano e margem SOBRE A VENDA (lucro / preco), nao sobre o custo. O markup
     * do revendedor e que se le sobre o custo dele.
     */
    public function test_the_margins_are_reported_in_basis_points(): void
    {
        $result = $this->part(grams: 32, minutes: 90);

        $this->assertSame(1_852_480, $result->producerProfitMicros(), '3,50 - 1,64752 = 1,85248 EUR');
        $this->assertSame(5_293, $result->producerMarginBp(), '52,93%');

        $this->assertSame(2_500_000, $result->resellerProfitMicros(), '6,00 - 3,50 = 2,50 EUR');
        $this->assertSame(4_167, $result->resellerMarginBp(), '41,67%');
        $this->assertSame(7_143, $result->resellerMarkupBp(), '71,43%');
    }

    /**
     * Em modo unitario a quantidade so multiplica os totais do trabalho. Nao
     * pode tocar em faixas nem em arredondamentos: seis pecas de 32 g nao sao
     * uma peca de 192 g, e o preco unitario tem de ser o mesmo de uma so.
     */
    public function test_the_quantity_only_multiplies_the_job_totals_in_per_unit_mode(): void
    {
        $one = $this->part(grams: 32, minutes: 90);
        $six = $this->part(grams: 32, minutes: 90, quantity: 6);

        $this->assertSame($one->productionCostMicros, $six->productionCostMicros);
        $this->assertSame($one->resalePriceMicros, $six->resalePriceMicros);

        $this->assertSame(2_100, $six->toArray()['job']['resalePriceCents'], '6 x 3,50 = 21,00 EUR');
        $this->assertSame(3_600, $six->toArray()['job']['retailPriceCents'], '6 x 6,00 = 36,00 EUR');
    }
}
