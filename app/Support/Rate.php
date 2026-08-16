<?php

namespace App\Support;

/**
 * Taxas em PONTOS BASE (bp): 10000 bp = 100%.
 *
 * Gemeo do App\Support\Money — o admin escreve "8" por cento, nos guardamos
 * 800. Pontos base e nao percentagem inteira porque uma taxa de falhas de 7,5%
 * ou uma comissao de 12,5% nao cabem num inteiro de percentagem, e mudar de
 * tipo mais tarde obrigava a uma migracao das definicoes.
 *
 * Ja aqui viveu um par fromMultiplier/toMultiplier, para quando as margens
 * eram multiplicadores ("1,70x"). Saiu com eles: ninguem sabia que margem e
 * que 1,70x dava sem fazer a conta ao contrario, e as margens passaram a ser
 * declaradas em percentagem.
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
}
