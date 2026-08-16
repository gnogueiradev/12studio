<?php

namespace Tests\Unit;

use App\Support\Rate;
use Tests\TestCase;

class RateTest extends TestCase
{
    /**
     * O admin escreve com virgula num campo e com ponto no seguinte. A leitura
     * e a mesma do Money::fromDecimal de proposito — havia duas formas de
     * escrever 7,5 e so uma regra a decidir qual dos separadores e o decimal.
     */
    public function test_a_percentage_becomes_basis_points(): void
    {
        $this->assertSame(800, Rate::fromPercent('8'));
        $this->assertSame(750, Rate::fromPercent('7,5'));
        $this->assertSame(750, Rate::fromPercent('7.5'));
        $this->assertSame(4_000, Rate::fromPercent('40'));
        $this->assertSame(0, Rate::fromPercent('0'));
    }

    /**
     * Ida e volta sem deriva: o formulario de definicoes le o bp guardado,
     * mostra-o, e uma gravacao sem alteracoes tem de devolver o mesmo inteiro.
     * Sem isto, abrir e guardar as definicoes mexia nos precos da loja.
     */
    public function test_a_rate_round_trips_through_the_form_without_drifting(): void
    {
        foreach ([800, 750, 1_250, 4_000, 4_500, 0] as $bp) {
            $this->assertSame($bp, Rate::fromPercent(Rate::toPercent($bp)));
        }
    }
}
