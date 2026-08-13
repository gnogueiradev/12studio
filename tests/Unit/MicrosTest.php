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
     * O arredondamento comercial do preco ao cliente tanto sobe como desce, e o
     * caso de referencia cai EM CIMA da fronteira: 6,125 EUR e exatamente um
     * quarto de degrau acima de 6,00 — arredonda para baixo. Um valor a meio
     * degrau (6,25) sobe.
     */
    public function test_round_half_up_to_lands_on_the_nearest_step_with_the_middle_going_up(): void
    {
        $this->assertSame(6_000_000, Micros::roundHalfUpTo(6_125_000, 500_000));
        $this->assertSame(6_500_000, Micros::roundHalfUpTo(6_250_000, 500_000));
        $this->assertSame(6_500_000, Micros::roundHalfUpTo(6_400_000, 500_000));
        $this->assertSame(25_000_000, Micros::roundHalfUpTo(24_500_000, 1_000_000));
        $this->assertSame(65_000_000, Micros::roundHalfUpTo(62_500_000, 5_000_000));
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
}
