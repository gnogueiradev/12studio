<?php

namespace Tests\Unit;

use App\Support\Rate;
use Tests\TestCase;

class RateTest extends TestCase
{
    /**
     * O admin escreve com virgula num campo e com ponto no seguinte. A leitura
     * e a mesma do Money::fromDecimal de proposito — havia duas formas de
     * escrever 1,75 e so uma regra a decidir qual dos separadores e o decimal.
     */
    public function test_a_multiplier_reads_the_same_with_a_comma_or_a_dot(): void
    {
        $this->assertSame(17_500, Rate::fromMultiplier('1,75'));
        $this->assertSame(17_500, Rate::fromMultiplier('1.75'));
        $this->assertSame(20_000, Rate::fromMultiplier('2'));
        $this->assertSame(16_000, Rate::fromMultiplier('1,6'));
    }

    public function test_a_percentage_becomes_basis_points(): void
    {
        $this->assertSame(800, Rate::fromPercent('8'));
        $this->assertSame(750, Rate::fromPercent('7,5'));
        $this->assertSame(0, Rate::fromPercent('0'));
    }

    /**
     * Ida e volta sem deriva: o formulario de definicoes le o bp guardado,
     * mostra-o, e uma gravacao sem alteracoes tem de devolver o mesmo inteiro.
     * Sem isto, abrir e guardar as definicoes mexia nos precos da loja.
     */
    public function test_a_rate_round_trips_through_the_form_without_drifting(): void
    {
        foreach ([800, 750, 1_250, 0] as $bp) {
            $this->assertSame($bp, Rate::fromPercent(Rate::toPercent($bp)));
        }

        foreach ([20_000, 19_000, 18_500, 17_500, 16_000, 10_000] as $bp) {
            $this->assertSame($bp, Rate::fromMultiplier(Rate::toMultiplier($bp)));
        }
    }
}
