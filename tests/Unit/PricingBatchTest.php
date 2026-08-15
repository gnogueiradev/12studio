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
            hourlyRateCents: 20,
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
        $this->assertSame(1_000_000, $result->machineCostMicros, '5 h x 0,20 EUR/h');
        $this->assertSame(150_000, $result->handlingCostMicros);
        $this->assertSame(67_000, $result->failureReserveMicros, '(0,34 + 1,00) x 5%');
        $this->assertSame(1_557_000, $result->productionCostMicros, 'custo real = 1,557 EUR');

        $this->assertSame(2_646_900, $result->rawResalePriceMicros);
        $this->assertSame(300, Micros::toCents($result->resalePriceMicros), '3,00 EUR de revenda');

        // A leitura que interessa: 0,34 EUR de plastico a valer 3,00 EUR. O
        // racio caiu de 19x para 8,8x com a formula nova — o tempo continua a
        // dominar, so que sem exagero.
        $this->assertGreaterThan(
            $result->filamentCostMicros * 8,
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
        $this->assertSame(144_445, $batch->machineCostMicros, '0,866667 EUR de maquina / 6');
        $this->assertSame(677_700, $batch->productionCostMicros, '4,0662 EUR de custo / 6');

        // Com a formula nova este caso sai pelo CHAO e nao pelo multiplicador:
        // 0,6777 x 1,70 = 1,152 EUR, abaixo do minimo de 1,50. O que o teste
        // prova continua a ser a divisao do trabalho pelas seis.
        $this->assertSame(1_500_000, $batch->rawResalePriceMicros, 'o chao de 1,50 EUR');
        $this->assertSame(150, Micros::toCents($batch->resalePriceMicros));
        $this->assertSame(900, $batch->toArray()['job']['resalePriceCents'], '6 x 1,50 = 9,00 EUR');
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
     * O lote tem manuseamento PROPRIO, e nao o custo fixo por peca. A razao
     * sobreviveu ao fim da tabela por peso: numa mesa, o trabalho que se faz uma
     * vez (montar, tirar a placa) dilui-se por todas as pecas, e um valor por
     * peca nao sabe exprimir essa diluicao.
     *
     * Quantidade 5 de proposito: a 4 unidades o manuseamento do lote daria
     * (0,20 + 0,40) / 4 = 0,15 EUR, exatamente igual ao custo fixo unitario — e
     * o teste passava sem conseguir distinguir os dois caminhos de codigo.
     */
    public function test_the_batch_handling_ignores_the_weight_of_the_plate(): void
    {
        $light = $this->calculate(PricingInput::MODE_BATCH, grams: 60, minutes: 200, quantity: 5);
        $heavy = $this->calculate(PricingInput::MODE_BATCH, grams: 900, minutes: 200, quantity: 5);

        $this->assertSame(
            $light->handlingCostMicros,
            $heavy->handlingCostMicros,
            'o peso da mesa nao pode mexer no manuseamento em lote',
        );
        $this->assertSame(140_000, $light->handlingCostMicros, '(0,20 + 5 x 0,10) / 5');
        $this->assertNotSame(150_000, $light->handlingCostMicros, 'e nao o custo fixo unitario');
    }

    /**
     * Consequencia assumida da regra acima: um lote de uma peca paga mais
     * manuseamento (0,30 EUR) do que a mesma peca em modo unitario (0,15 EUR).
     * Esta certo — um lote de um ainda paga uma montagem de mesa.
     */
    public function test_a_batch_of_one_pays_more_handling_than_the_same_part_alone(): void
    {
        $alone = $this->calculate(PricingInput::MODE_PER_UNIT, grams: 45, minutes: 150);
        $batchOfOne = $this->calculate(PricingInput::MODE_BATCH, grams: 45, minutes: 150, quantity: 1);

        $this->assertSame(150_000, $alone->handlingCostMicros);
        $this->assertSame(300_000, $batchOfOne->handlingCostMicros);

        $this->assertSame($alone->filamentCostMicros, $batchOfOne->filamentCostMicros);
        $this->assertSame($alone->machineCostMicros, $batchOfOne->machineCostMicros);
        $this->assertSame(150_000, $batchOfOne->productionCostMicros - $alone->productionCostMicros);
    }

    /**
     * Em modo unitario o utilizador descreve UMA peca e a quantidade so
     * multiplica os totais: nunca entra no calculo da unidade. O contrario —
     * somar o peso e o tempo primeiro — encarecia cada peca sem razao nenhuma.
     */
    public function test_per_unit_mode_multiplies_the_job_totals_by_the_quantity(): void
    {
        $six = $this->calculate(PricingInput::MODE_PER_UNIT, grams: 45, minutes: 150, quantity: 6);
        $one = $this->calculate(PricingInput::MODE_PER_UNIT, grams: 45, minutes: 150);

        $this->assertSame(150_000, $six->handlingCostMicros);
        $this->assertSame($one->productionCostMicros, $six->productionCostMicros, 'a quantidade nao toca na unidade');
        $this->assertSame(1_478_250, $six->productionCostMicros);

        $job = $six->toArray()['job'];
        $this->assertSame(887, $job['productionCostCents'], '6 x 1,47825 = 8,8695 EUR');
        $this->assertSame(1_800, $job['resalePriceCents']);
        $this->assertSame(3_300, $job['retailPriceCents']);
    }

    /**
     * O chao e o arredondamento aplicam-se ao custo da UNIDADE, nunca ao do
     * trabalho. Uma mesa grande de pecas baratas nao pode ser tratada como uma
     * peca cara so por ser grande.
     *
     * Aqui da para ver a diferenca em numeros: o trabalho custa 7,86 EUR e a
     * unidade 0,6552. Passar o trabalho pela formula daria ceil(7,86 x 1,70) =
     * 13,50 EUR, ou seja 1,125 EUR por peca — e nao os 1,50 EUR que o chao
     * garante a cada uma.
     */
    public function test_the_floor_applies_to_the_unit_cost_never_to_the_job_cost(): void
    {
        $batch = $this->calculate(PricingInput::MODE_BATCH, grams: 264, minutes: 500, quantity: 12);

        $this->assertSame(655_200, $batch->productionCostMicros, 'custo unitario = 0,6552 EUR');
        $this->assertLessThanOrEqual(200 * Micros::PER_CENT, $batch->productionCostMicros);
        $this->assertSame(1_500_000, $batch->resalePriceMicros, 'o chao aplicado a UNIDADE');
    }
}
