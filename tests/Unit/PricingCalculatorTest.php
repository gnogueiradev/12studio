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
     * Bambu Lab A1 a 0,20 EUR/h.
     */
    private function part(int $grams, int $minutes, int $extraCents = 0, int $quantity = 1): PricingResult
    {
        return $this->calculator->calculate(new PricingInput(
            mode: PricingInput::MODE_PER_UNIT,
            weightGrams: $grams,
            minutes: $minutes,
            pricePerKgCents: 1_700,
            hourlyRateCents: 20,
            extraCostCents: $extraCents,
            quantity: $quantity,
        ));
    }

    /**
     * O CASO DE REFERENCIA. E o preco que o dono calculou a mao para uma peca
     * real — 17 EUR/kg, 45 g, 2h30 na A1 — e e ele que define o comportamento
     * oficial da calculadora. Cada decisao de arredondamento no App\Support\
     * Micros existe para reproduzir estes numeros exatamente; se este teste
     * ficar vermelho, e a formula que mudou, nao o teste.
     *
     * Nao ha um unico arredondamento perdido: todos os passos caem em inteiros
     * exatos. Com floats o 2,513025 seria 2,5130249... e o ceil dava 2,50 EUR
     * em vez de 3,00.
     */
    public function test_the_reference_part_prices_at_three_euros_of_resale_and_five_fifty_of_retail(): void
    {
        $result = $this->part(grams: 45, minutes: 150);

        $this->assertSame(765_000, $result->filamentCostMicros, '45 g x 0,017 EUR/g = 0,765 EUR');
        $this->assertSame(500_000, $result->machineCostMicros, '2,5 h x 0,20 EUR/h = 0,50 EUR');
        $this->assertSame(150_000, $result->handlingCostMicros, 'custo fixo por peca = 0,15 EUR');
        $this->assertSame(63_250, $result->failureReserveMicros, '(0,765 + 0,50) x 5% = 0,06325 EUR');
        $this->assertSame(0, $result->extraCostMicros);
        $this->assertSame(1_478_250, $result->productionCostMicros, 'custo real = 1,47825 EUR');

        $this->assertSame(17_000, $result->resaleMultiplierBp, '1,70x, unico');
        $this->assertSame(2_513_025, $result->rawResalePriceMicros, '1,47825 x 1,70 = 2,513025 EUR');
        $this->assertSame(3_000_000, $result->resalePriceMicros, 'para cima aos 0,50 -> 3,00 EUR');

        $this->assertSame(5_250_000, $result->rawRetailPriceMicros, '3,00 x 1,75 = 5,25 EUR');
        $this->assertSame(5_500_000, $result->retailPriceMicros, 'ao 0,50 mais proximo -> 5,50 EUR');
        $this->assertFalse($result->retailBumped, '5,50 >= 3,00 x 1,60 = 4,80');

        $this->assertSame(148, Micros::toCents($result->productionCostMicros));
        $this->assertSame(300, Micros::toCents($result->resalePriceMicros));
        $this->assertSame(550, Micros::toCents($result->retailPriceMicros));
    }

    /**
     * O irmao do teste de referencia, e o que apanha um toCents() prematuro.
     * Se alguem arredondar o filamento a 0,76 EUR "porque e o que se mostra", o
     * custo desvia-se e o preco de revenda continua a dar 3,00 — o erro so
     * aparece meses depois, numa peca onde o degrau calha ao contrario.
     */
    public function test_no_intermediate_value_is_rounded_down_to_the_cent(): void
    {
        $result = $this->part(grams: 45, minutes: 150);

        $this->assertNotSame(0, $result->filamentCostMicros % Micros::PER_CENT, '0,765 nao e um numero redondo de centimos');
        $this->assertSame(1_478_250, $result->productionCostMicros);
        $this->assertSame(2_513_025, $result->rawResalePriceMicros);
    }

    /**
     * A razao de ser desta versao inteira. Mesmo filamento, mesmo peso, metade
     * do tempo: o preco TEM de descer. Na formula antiga, baseada na gramagem,
     * estas duas pecas custavam o mesmo.
     */
    public function test_halving_the_print_time_drops_the_resale_price_to_two_fifty(): void
    {
        $fast = $this->part(grams: 45, minutes: 75);
        $slow = $this->part(grams: 45, minutes: 150);

        $this->assertSame($fast->filamentCostMicros, $slow->filamentCostMicros, 'o material e o mesmo');

        $this->assertSame(250_000, $fast->machineCostMicros, '1,25 h x 0,20 EUR/h');
        $this->assertSame(50_750, $fast->failureReserveMicros);
        $this->assertSame(1_215_750, $fast->productionCostMicros, 'custo real = 1,21575 EUR');
        $this->assertSame(2_066_775, $fast->rawResalePriceMicros);

        $this->assertSame(250, Micros::toCents($fast->resalePriceMicros), '1h15 -> 2,50 EUR');
        $this->assertSame(300, Micros::toCents($slow->resalePriceMicros), '2h30 -> 3,00 EUR');
    }

    /**
     * Um ima ou uma caixa entram DEPOIS da impressao. Uma impressao falhada nao
     * os consome, logo nao pagam seguro de falha — so filamento e maquina
     * pagam.
     */
    public function test_the_extra_cost_does_not_pay_the_failure_reserve(): void
    {
        $bare = $this->part(grams: 45, minutes: 150);
        $withExtras = $this->part(grams: 45, minutes: 150, extraCents: 65);

        $this->assertSame($bare->failureReserveMicros, $withExtras->failureReserveMicros);
        $this->assertSame(650_000, $withExtras->extraCostMicros);
        $this->assertSame(
            $bare->productionCostMicros + 650_000,
            $withExtras->productionCostMicros,
            'os extras entram a cru, sem reserva por cima',
        );
    }

    /**
     * O manuseamento e um custo FIXO, e nao uma tabela por peso.
     *
     * Aqui houve faixas (0,25 EUR ate 50 g, 1,00 EUR acima de 500 g). Sairam
     * porque o que cresce com o tamanho da peca e o tempo de impressao, que ja
     * se cobra a parte: tirar da mesa e ensacar uma peca de 500 g nao demora
     * quatro vezes o que demora uma de 50 g, e cobrar as duas coisas era pagar
     * duas vezes pelo mesmo.
     */
    public function test_the_handling_cost_is_the_same_whatever_the_part_weighs(): void
    {
        foreach ([1, 50, 51, 150, 300, 500, 501, 2_000] as $grams) {
            $this->assertSame(
                150_000,
                $this->part($grams, minutes: 10)->handlingCostMicros,
                "{$grams} g pagou um manuseamento diferente do fixo",
            );
        }
    }

    /**
     * O multiplicador de revenda e UNICO, e nao uma tabela por faixa de custo.
     *
     * A tabela progressiva (2,00x ate 2 EUR de custo, 1,90x ate 5 EUR, ...) fazia
     * o preco saltar de forma descontinua ao atravessar um limiar: um custo de
     * 2,00 EUR dava 4,00 EUR de revenda e um de 2,02 EUR dava 3,84 — a peca mais
     * cara vendia-se mais barato. Quem protege as pecas pequenas passou a ser o
     * chao de 1,50 EUR, que nao tem essa inversao.
     */
    public function test_the_resale_multiplier_is_the_same_at_every_production_cost(): void
    {
        foreach ([[1, 1], [45, 150], [100, 300], [200, 400], [300, 900]] as [$grams, $minutes]) {
            $this->assertSame(
                17_000,
                $this->part($grams, $minutes)->resaleMultiplierBp,
                "{$grams} g / {$minutes} min saiu com outro multiplicador",
            );
        }
    }

    /**
     * O preco de revenda vive numa lista comercial (1,50 / 2,00 / 2,50 ...) e
     * arredonda sempre para CIMA. Para baixo, o multiplicador de margem que
     * acabamos de aplicar era parcialmente devolvido ao cliente.
     */
    public function test_the_resale_price_is_always_rounded_up_to_the_next_fifty_cents(): void
    {
        foreach ([[45, 150, 300], [45, 75, 250], [45, 250, 350]] as [$grams, $minutes, $expected]) {
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

        $this->assertSame(171_350, $tiny->productionCostMicros, 'custo real = 0,17135 EUR');
        $this->assertLessThan(1_500_000, Micros::applyBp($tiny->productionCostMicros, 17_000));
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
     * Com o minimo apertado a 1,72x, uma peca de revenda 3,50 EUR cai na rede:
     * 3,50 x 1,75 = 6,125 arredonda para 6,00, e 6,00 < 3,50 x 1,72 = 6,02.
     *
     * A revenda tem de NAO ser multipla de 2,00 EUR para o arredondamento ter
     * para onde descer — num 3,00 EUR o 5,25 sobe, e nao ha nada a proteger.
     */
    public function test_the_retail_price_is_bumped_when_it_would_leave_the_reseller_under_the_minimum(): void
    {
        app(SettingService::class)->set(PricingSettings::KEY_MINIMUM_RETAIL_MULTIPLIER_BP, 17_200);

        $result = app(PricingCalculator::class)->calculate(new PricingInput(
            mode: PricingInput::MODE_PER_UNIT,
            weightGrams: 45,
            minutes: 250,
            pricePerKgCents: 1_700,
            hourlyRateCents: 20,
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
        $result = $this->part(grams: 45, minutes: 150);

        $this->assertSame(1_521_750, $result->producerProfitMicros(), '3,00 - 1,47825 = 1,52175 EUR');
        // 5072,5 bp exatos: o unico caso em todo o sistema que exercita o
        // desempate ao meio para CIMA do Micros::divRound. Um intdiv puro
        // devolvia 5072 e ninguem dava por isso.
        $this->assertSame(5_073, $result->producerMarginBp(), '50,73%');

        $this->assertSame(2_500_000, $result->resellerProfitMicros(), '5,50 - 3,00 = 2,50 EUR');
        $this->assertSame(4_545, $result->resellerMarginBp(), '45,45%');
        $this->assertSame(8_333, $result->resellerMarkupBp(), '83,33%');
    }

    /**
     * Em modo unitario a quantidade so multiplica os totais do trabalho: nunca
     * entra no calculo da unidade, nem no arredondamento. Seis pecas iguais
     * valem seis vezes uma, e nao uma peca seis vezes maior.
     */
    public function test_the_quantity_only_multiplies_the_job_totals_in_per_unit_mode(): void
    {
        $one = $this->part(grams: 45, minutes: 150);
        $six = $this->part(grams: 45, minutes: 150, quantity: 6);

        $this->assertSame($one->productionCostMicros, $six->productionCostMicros);
        $this->assertSame($one->resalePriceMicros, $six->resalePriceMicros);

        $this->assertSame(1_800, $six->toArray()['job']['resalePriceCents'], '6 x 3,00 = 18,00 EUR');
        $this->assertSame(3_300, $six->toArray()['job']['retailPriceCents'], '6 x 5,50 = 33,00 EUR');
    }
}
