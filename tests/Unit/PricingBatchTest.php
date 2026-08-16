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

    private function calculate(
        string $mode,
        int $grams,
        int $minutes,
        int $quantity = 1,
        int $packagingCents = 0,
        int $componentsCents = 0,
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
            quantity: $quantity,
        ));
    }

    /**
     * A peca que uma formula baseada em gramas estraga por completo: pouco
     * plastico, muitas horas. Sao 0,34 EUR de material, mas prende a impressora
     * cinco horas — e cinco horas de impressora sao cinco horas em que mais
     * nada se imprime.
     *
     * Com as parcelas separadas da para ver ONDE e que o tempo pesa: a
     * depreciacao sozinha (0,50 EUR) ja custa mais do que o plastico todo.
     */
    public function test_a_long_print_of_little_filament_is_priced_by_the_machine(): void
    {
        $result = $this->calculate(PricingInput::MODE_PER_UNIT, grams: 20, minutes: 300);

        $this->assertSame(340_000, $result->filamentCostMicros, '0,34 EUR de plastico');
        $this->assertSame(102_950, $result->electricityCostMicros, '5 h de luz');
        $this->assertSame(500_000, $result->depreciationCostMicros, '5 h x 0,10 EUR/h');
        $this->assertSame(200_000, $result->maintenanceCostMicros, '5 h x 0,04 EUR/h');

        $this->assertGreaterThan(
            $result->filamentCostMicros,
            $result->depreciationCostMicros,
            'a maquina a amortizar-se ja custa mais do que o plastico',
        );

        $this->assertSame(1_904_860, $result->productionCostMicros);
        $this->assertSame(350, Micros::toCents($result->wholesalePriceMicros), '3,50 EUR de revenda');
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
        $this->assertSame(14_871, $batch->electricityCostMicros, '0,089223 EUR de luz / 6');
        $this->assertSame(72_222, $batch->depreciationCostMicros, '0,433333 EUR de amortizacao / 6');

        $this->assertSame(1_500, $batch->toArray()['job']['wholesalePriceCents'], '6 x 2,50 EUR');
    }

    /**
     * Em lote a mao de obra decompoe-se: o que se faz UMA vez (montar a mesa,
     * lancar, tirar a placa) e o que se faz a CADA peca (rebarbar, limpar,
     * ensacar). E daqui que vem a economia real de imprimir doze de uma vez.
     */
    public function test_a_batch_pays_one_setup_plus_active_labor_per_unit(): void
    {
        $six = $this->calculate(PricingInput::MODE_BATCH, grams: 132, minutes: 260, quantity: 6);

        $this->assertSame(35, $six->laborMinutes, '5 de montagem + 6 x 5 de acabamento');
        $this->assertSame(777_778, $six->laborCostMicros, '4,666667 EUR / 6');

        $twelve = $this->calculate(PricingInput::MODE_BATCH, grams: 264, minutes: 500, quantity: 12);

        $this->assertSame(65, $twelve->laborMinutes, '5 + 12 x 5');
        $this->assertLessThan(
            $six->laborCostMicros,
            $twelve->laborCostMicros,
            'a montagem dilui-se: mais pecas na mesa, menos preparacao por peca',
        );
    }

    /**
     * A preparacao dilui-se, o acabamento NAO. E a distincao que faz o modo
     * lote valer a pena: se tudo se diluisse, uma mesa de cem pecas saia de
     * graca; se nada se diluisse, o modo lote nao servia para nada.
     */
    public function test_only_the_setup_dilutes_never_the_finishing(): void
    {
        $six = $this->calculate(PricingInput::MODE_BATCH, grams: 132, minutes: 260, quantity: 6);
        $twelve = $this->calculate(PricingInput::MODE_BATCH, grams: 264, minutes: 500, quantity: 12);

        // Minutos de trabalho POR PECA: 35/6 = 5,83 contra 65/12 = 5,42. Nunca
        // desce abaixo dos 5 minutos de acabamento, por muito grande que a mesa
        // seja — so se aproxima deles.
        $this->assertGreaterThan(5, $six->laborMinutes / 6);
        $this->assertGreaterThan(5, $twelve->laborMinutes / 12);
        $this->assertLessThan($six->laborMinutes / 6, $twelve->laborMinutes / 12);
    }

    /**
     * O peso da mesa nao mexe na mao de obra: rebarbar e ensacar uma peca de
     * 400 g nao demora quatro vezes o que demora uma de 100 g. O que cresce com
     * o tamanho e o tempo de impressao, que ja se paga a parte.
     */
    public function test_the_labor_ignores_the_weight_of_the_plate(): void
    {
        $light = $this->calculate(PricingInput::MODE_BATCH, grams: 60, minutes: 200, quantity: 5);
        $heavy = $this->calculate(PricingInput::MODE_BATCH, grams: 900, minutes: 200, quantity: 5);

        $this->assertSame($light->laborCostMicros, $heavy->laborCostMicros);
        $this->assertNotSame($light->filamentCostMicros, $heavy->filamentCostMicros, 'mas o material sim');
    }

    /**
     * A embalagem e os componentes NAO se dividem pela mesa: doze pecas levam
     * doze sacos e doze imanes. Sao as unicas parcelas que ja entram por
     * unidade, e por isso atravessam o divisor sem lhe tocar.
     */
    public function test_the_packaging_and_components_are_not_divided_by_the_batch(): void
    {
        $batch = $this->calculate(
            PricingInput::MODE_BATCH,
            grams: 264,
            minutes: 500,
            quantity: 12,
            packagingCents: 15,
            componentsCents: 40,
        );

        $this->assertSame(150_000, $batch->packagingCostMicros, '0,15 EUR por peca, nao 0,15/12');
        $this->assertSame(400_000, $batch->componentsCostMicros);
    }

    /**
     * Consequencia assumida da regra da montagem: um lote de uma peca paga mais
     * mao de obra do que a mesma peca em modo unitario. Esta certo — um lote de
     * um ainda paga uma montagem de mesa.
     */
    public function test_a_batch_of_one_pays_more_labor_than_the_same_part_alone(): void
    {
        $alone = $this->calculate(PricingInput::MODE_PER_UNIT, grams: 45, minutes: 150);
        $batchOfOne = $this->calculate(PricingInput::MODE_BATCH, grams: 45, minutes: 150, quantity: 1);

        $this->assertSame(5, $alone->laborMinutes);
        $this->assertSame(10, $batchOfOne->laborMinutes, '5 de montagem + 5 de acabamento');

        $this->assertSame($alone->filamentCostMicros, $batchOfOne->filamentCostMicros);
        $this->assertSame($alone->depreciationCostMicros, $batchOfOne->depreciationCostMicros);
        $this->assertGreaterThan($alone->productionCostMicros, $batchOfOne->productionCostMicros);
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

        $this->assertSame(5, $six->laborMinutes, 'sem montagem: nao ha mesa nenhuma a montar');
        $this->assertSame($one->productionCostMicros, $six->productionCostMicros, 'a quantidade nao toca na unidade');

        $job = $six->toArray()['job'];
        $this->assertSame(6 * Micros::toCents($one->wholesalePriceMicros), $job['wholesalePriceCents']);
        $this->assertSame(6 * Micros::toCents($one->retailPriceMicros), $job['retailPriceCents']);
    }

    /**
     * O chao e o arredondamento aplicam-se ao custo da UNIDADE, nunca ao do
     * trabalho. Uma mesa grande de pecas baratas nao pode ser tratada como uma
     * peca cara so por ser grande.
     */
    public function test_the_floor_applies_to_the_unit_cost_never_to_the_job_cost(): void
    {
        $batch = $this->calculate(PricingInput::MODE_BATCH, grams: 60, minutes: 120, quantity: 12);

        $this->assertLessThan(
            Micros::fromCents(150),
            $batch->productionCostMicros,
            'cada peca custa bem menos do que o chao de 1,50 EUR',
        );
        $this->assertSame(1_500_000, $batch->rawWholesalePriceMicros, 'o chao aplicado a UNIDADE');
        $this->assertSame(1_500_000, $batch->wholesalePriceMicros);

        // O trabalho todo custa uns 10 EUR; passa-lo pela formula e dividir
        // depois dava um preco por peca que ignorava o chao de cada uma.
        $this->assertSame(1_800, $batch->toArray()['job']['wholesalePriceCents'], '12 x 1,50 EUR');
    }
}
