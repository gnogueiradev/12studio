<?php

namespace Tests\Unit;

use App\Support\Micros;
use Tests\TestCase;

class MicrosTest extends TestCase
{
    public function test_cents_convert_to_micros_and_back(): void
    {
        $this->assertSame(1_500_000, Micros::fromCents(150));
        $this->assertSame(150, Micros::toCents(1_500_000));
    }

    /**
     * O intdiv corta sempre para baixo. Numa cadeia de cinco divisoes (maquina,
     * reserva, multiplicador de revenda, multiplicador de retalho, margens) o
     * erro acumulava-se todo no mesmo sentido e o preco final descia sozinho.
     */
    public function test_a_division_rounds_half_up_instead_of_truncating(): void
    {
        $this->assertSame(3, Micros::divRound(5, 2));
        $this->assertSame(4, Micros::divRound(7, 2));
        $this->assertSame(1, Micros::divRound(2, 3));
    }

    /**
     * A meia unidade que o divRound soma nao pode empurrar uma divisao exata
     * para cima: (2n+d)/(2d) = k+0,5 e o intdiv corta o meio. Sem isto, 0,75 EUR
     * de custo de maquina passava a 0,750001 e o caso de referencia falhava.
     */
    public function test_an_exact_division_is_not_pushed_up_by_the_added_half(): void
    {
        $this->assertSame(2, Micros::divRound(4, 2));
        $this->assertSame(750_000, Micros::divRound(90 * 50 * Micros::PER_CENT, 60));
        $this->assertSame(100, Micros::toCents(1_000_000));
    }

    /**
     * O preco de revenda sobe sempre ao proximo degrau de 0,50 EUR — mas um
     * valor que ja seja multiplo exato tem de ficar quieto. Um `ceil` ingenuo
     * sobre inteiros que somasse o degrau inteiro transformava 3,50 em 4,00.
     */
    public function test_ceil_to_is_idempotent_on_an_exact_multiple(): void
    {
        $this->assertSame(3_500_000, Micros::ceilTo(3_500_000, 500_000));
        $this->assertSame(3_500_000, Micros::ceilTo(3_295_040, 500_000));
        $this->assertSame(500_000, Micros::ceilTo(1, 500_000));
        $this->assertSame(0, Micros::ceilTo(0, 500_000));
    }

    /**
     * As taxas vivem em pontos base para caber um 7,5% sem mudar de tipo.
     * 10000 bp = 100%, por isso aplicar 10000 nao pode mexer no valor.
     */
    public function test_a_basis_point_rate_applies_without_losing_a_micro(): void
    {
        $this->assertSame(1_294_000, Micros::applyBp(1_294_000, 10_000));
        $this->assertSame(103_520, Micros::applyBp(1_294_000, 800));
        $this->assertSame(3_295_040, Micros::applyBp(1_647_520, 20_000));
        $this->assertSame(6_125_000, Micros::applyBp(3_500_000, 17_500));
    }

    /**
     * O passo mais sensivel da formula nao tem divisao nenhuma: um centimo sao
     * 10.000 micros e um kg sao 1000 g, logo o /1000 do preco por kg dissolve-se
     * na constante (10000/1000 = 10). E daqui que sai o 0,544 EUR exato.
     */
    public function test_the_filament_conversion_is_exact_because_the_division_disappears(): void
    {
        $this->assertSame(10, Micros::PER_CENT / 1000);
        $this->assertSame(544_000, 32 * 1700 * 10);
    }

    /**
     * A tarifa da eletricidade e a manutencao a hora nao cabem num centimo:
     * 0,1420 EUR/kWh guardado em centimos era 14, e o custo de energia saia 4%
     * ao lado. O admin escreve com virgula ou com ponto, como no resto do
     * backoffice.
     */
    public function test_a_sub_cent_amount_survives_the_round_trip(): void
    {
        $this->assertSame(142_000, Micros::fromDecimal('0,1420'));
        $this->assertSame(142_000, Micros::fromDecimal('0.142'));
        $this->assertSame(40_000, Micros::fromDecimal('0,04'));
        $this->assertSame(8_000_000, Micros::fromDecimal('8'));

        $this->assertSame('0.1420', Micros::toDecimal(142_000));
        $this->assertSame('0.0400', Micros::toDecimal(40_000));
        $this->assertSame('8.00', Micros::toDecimal(8_000_000, 2));
    }

    /**
     * O que o centimo perdia: 0,1420 EUR/kWh e 0,0400 EUR/h nao sao multiplos
     * de PER_CENT, e e exatamente por isso que estes dois parametros vivem em
     * micros e nao ao lado do resto do dinheiro.
     */
    public function test_the_electricity_tariff_is_not_a_whole_number_of_cents(): void
    {
        $this->assertNotSame(0, Micros::fromDecimal('0,1420') % Micros::PER_CENT);
        $this->assertNotSame(0, Micros::fromDecimal('0,0425') % Micros::PER_CENT);
    }
}
