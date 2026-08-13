<?php

namespace Tests\Unit;

use App\Services\PricingCalculator;
use App\Support\Micros;
use App\Support\PricingInput;
use App\Support\PricingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingBatchTest extends TestCase
{
    use RefreshDatabase;

    private PricingCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = app(PricingCalculator::class);
    }

    private function calculate(string $mode, int $grams, int $minutes, int $quantity = 1): PricingResult
    {
        return $this->calculator->calculate(new PricingInput(
            mode: $mode,
            weightGrams: $grams,
            minutes: $minutes,
            pricePerKgCents: 1_700,
            hourlyRateCents: 50,
            quantity: $quantity,
        ));
    }

    /**
     * A peca que a formula antiga estragava por completo: pouco plastico, muitas
     * horas. Sao 0,34 EUR de material, mas prende a impressora cinco horas — e
     * cinco horas de impressora sao cinco horas em que mais nada se imprime.
     *
     * Caso obrigatorio do spec.
     */
    public function test_a_long_print_of_little_filament_is_priced_by_the_machine(): void
    {
        $result = $this->calculate(PricingInput::MODE_PER_UNIT, grams: 20, minutes: 300);

        $this->assertSame(340_000, $result->filamentCostMicros, '0,34 EUR de plastico');
        $this->assertSame(2_500_000, $result->machineCostMicros, '5 h x 0,50 EUR/h');
        $this->assertSame(250_000, $result->handlingCostMicros);
        $this->assertSame(227_200, $result->failureReserveMicros, '(0,34 + 2,50) x 8%');
        $this->assertSame(3_317_200, $result->productionCostMicros, 'custo real = 3,3172 EUR');

        $this->assertSame(19_000, $result->resaleMultiplierBp, 'custo entre 2 e 5 EUR -> 1,90x');
        $this->assertSame(6_302_680, $result->rawResalePriceMicros);
        $this->assertSame(650, Micros::toCents($result->resalePriceMicros), '6,50 EUR de revenda');

        // A leitura que interessa: 0,34 EUR de plastico a valer 6,50 EUR.
        $this->assertGreaterThan(
            $result->filamentCostMicros * 15,
            $result->resalePriceMicros,
            'o tempo tem de dominar o preco',
        );
    }

    /**
     * O modo lote existe para isto: seis pecas na mesma mesa nao demoram seis
     * vezes o tempo de uma. O utilizador da o que o slicer diz para a mesa toda
     * — 132 g e 4h20 — e o custo divide-se pelas seis.
     */
    public function test_a_batch_splits_the_job_cost_across_the_units(): void
    {
        $batch = $this->calculate(PricingInput::MODE_BATCH, grams: 132, minutes: 260, quantity: 6);

        // As parcelas apresentadas ja vem por unidade.
        $this->assertSame(374_000, $batch->filamentCostMicros, '2,244 EUR de material / 6');
        $this->assertSame(361_111, $batch->machineCostMicros, '2,166667 EUR de maquina / 6');
        $this->assertSame(927_253, $batch->productionCostMicros, '5,56352 EUR de custo / 6');

        $this->assertSame(200, Micros::toCents($batch->resalePriceMicros));
        $this->assertSame(1_200, $batch->toArray()['job']['resalePriceCents'], '6 x 2,00 = 12,00 EUR');
    }

    /**
     * Em lote o manuseamento decompoe-se: o que se faz uma vez (montar a mesa,
     * tirar a placa) e o que se faz a cada peca (rebarbar, ensacar).
     */
    public function test_a_batch_pays_one_setup_plus_one_handling_per_unit(): void
    {
        $six = $this->calculate(PricingInput::MODE_BATCH, grams: 132, minutes: 260, quantity: 6);

        // 0,20 EUR de montagem + 6 x 0,10 EUR = 0,80 EUR, dividido por 6. A
        // divisao nao e exata: 133.333 x 6 = 799.998, dois micros abaixo dos
        // 800.000 do trabalho. E o preco de repartir um custo indivisivel, e
        // fica quatro casas decimais abaixo do centimo.
        $this->assertSame(133_333, $six->handlingCostMicros);
        $this->assertLessThan(6, 800_000 - $six->handlingCostMicros * 6);

        $twelve = $this->calculate(PricingInput::MODE_BATCH, grams: 264, minutes: 500, quantity: 12);

        $this->assertSame(116_667, $twelve->handlingCostMicros, '(0,20 + 12 x 0,10) / 12');
        $this->assertLessThan(
            $six->handlingCostMicros,
            $twelve->handlingCostMicros,
            'a montagem dilui-se: mais pecas na mesa, menos manuseamento por peca',
        );
    }

    /**
     * A tabela por peso e calibrada por PECA. Aplicada ao peso de uma mesa
     * inteira, a economia inverte-se: 10 porta-chaves de 30 g cairiam na faixa
     * dos 300 g — 0,50 EUR no trabalho todo, 0,05 EUR por peca — menos do que
     * uma unica peca de 30 g pagaria sozinha. Por isso em lote nao se usa.
     */
    public function test_the_weight_tier_is_never_used_in_a_batch(): void
    {
        $light = $this->calculate(PricingInput::MODE_BATCH, grams: 60, minutes: 200, quantity: 4);
        $heavy = $this->calculate(PricingInput::MODE_BATCH, grams: 900, minutes: 200, quantity: 4);

        $this->assertSame(
            $light->handlingCostMicros,
            $heavy->handlingCostMicros,
            'o peso da mesa nao pode mexer no manuseamento em lote',
        );
        $this->assertSame(150_000, $light->handlingCostMicros, '(0,20 + 4 x 0,10) / 4');
    }

    /**
     * Consequencia assumida da regra acima: um lote de uma peca paga mais
     * manuseamento (0,30 EUR) do que a mesma peca em modo unitario (0,25 EUR).
     * Esta certo — um lote de um ainda paga uma montagem de mesa.
     */
    public function test_a_batch_of_one_pays_more_handling_than_the_same_part_alone(): void
    {
        $alone = $this->calculate(PricingInput::MODE_PER_UNIT, grams: 32, minutes: 90);
        $batchOfOne = $this->calculate(PricingInput::MODE_BATCH, grams: 32, minutes: 90, quantity: 1);

        $this->assertSame(250_000, $alone->handlingCostMicros);
        $this->assertSame(300_000, $batchOfOne->handlingCostMicros);

        $this->assertSame($alone->filamentCostMicros, $batchOfOne->filamentCostMicros);
        $this->assertSame($alone->machineCostMicros, $batchOfOne->machineCostMicros);
        $this->assertSame(50_000, $batchOfOne->productionCostMicros - $alone->productionCostMicros);
    }

    /**
     * Em modo unitario o utilizador descreve UMA peca e a quantidade so
     * multiplica os totais. O contrario — somar o peso e o tempo primeiro —
     * empurrava seis pecas de 32 g para a faixa dos 192 g e encarecia cada uma
     * sem razao nenhuma.
     */
    public function test_per_unit_mode_multiplies_the_job_totals_by_the_quantity(): void
    {
        $six = $this->calculate(PricingInput::MODE_PER_UNIT, grams: 32, minutes: 90, quantity: 6);

        $this->assertSame(250_000, $six->handlingCostMicros, 'a faixa e a de 32 g, nao a de 192 g');
        $this->assertSame(1_647_520, $six->productionCostMicros);

        $job = $six->toArray()['job'];
        $this->assertSame(989, $job['productionCostCents'], '6 x 1,64752 = 9,88512 EUR');
        $this->assertSame(2_100, $job['resalePriceCents']);
        $this->assertSame(3_600, $job['retailPriceCents']);
    }

    /**
     * As faixas (multiplicador de revenda, arredondamento comercial) aplicam-se
     * ao custo da UNIDADE, nunca ao do trabalho. Uma mesa de 12 peritos baratos
     * nao pode saltar para o multiplicador dos artigos caros so por ser grande.
     */
    public function test_the_tiers_are_applied_to_the_unit_cost_never_to_the_job_cost(): void
    {
        $batch = $this->calculate(PricingInput::MODE_BATCH, grams: 264, minutes: 500, quantity: 12);

        $this->assertLessThanOrEqual(200 * Micros::PER_CENT, $batch->productionCostMicros);
        $this->assertSame(20_000, $batch->resaleMultiplierBp, 'custo unitario <= 2 EUR -> 2,00x');
    }
}
