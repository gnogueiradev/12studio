<?php

namespace App\Support;

/**
 * Taxas em PONTOS BASE (bp): 10000 bp = 100% = 1,00x.
 *
 * Gemeo do App\Support\Money — o admin escreve "8" por cento e "1,75" de
 * multiplicador, nos guardamos 800 e 17500. Pontos base e nao percentagem
 * inteira porque uma reserva de falha de 7,5% ou um multiplicador de 1,85x nao
 * cabem num inteiro de percentagem, e mudar de tipo mais tarde obrigava a uma
 * migracao das definicoes.
 *
 * As duas unidades sao a MESMA escala vista de dois lados: 8% e 0,08x sao ambos
 * 800 bp. O que muda e so como se le no formulario.
 */
class Rate
{
    public const PER_UNIT = 10_000;

    /** "8" (por cento) -> 800 bp. */
    public static function fromPercent(string $value): int
    {
        return (int) round(Money::normalizeDecimal($value) * 100);
    }

    /** 800 bp -> "8.00" (por cento), para pre-preencher um input. */
    public static function toPercent(int $bp): string
    {
        return number_format($bp / 100, 2, '.', '');
    }

    /** "1,75" (multiplicador) -> 17500 bp. */
    public static function fromMultiplier(string $value): int
    {
        return (int) round(Money::normalizeDecimal($value) * self::PER_UNIT);
    }

    /** 17500 bp -> "1.75" (multiplicador), para pre-preencher um input. */
    public static function toMultiplier(int $bp): string
    {
        return number_format($bp / self::PER_UNIT, 2, '.', '');
    }
}
