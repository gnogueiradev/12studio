<?php

namespace App\Support;

class Money
{
    /**
     * Converte euros escritos por humanos em centimos inteiros.
     * Aceita "12,50", "12.50", "1 234,56" e "€ 12,50" — o admin escreve
     * o que lhe apetece, nos guardamos sempre inteiros (nunca floats).
     */
    public static function fromDecimal(string $value): int
    {
        $clean = preg_replace('/[^0-9,.\-]/', '', $value) ?? '';

        // Se tem os dois separadores, o ultimo e o decimal ("1.234,56" ou "1,234.56").
        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $clean = str_replace($thousandsSeparator, '', $clean);
            $clean = str_replace($decimalSeparator, '.', $clean);
        } else {
            $clean = str_replace(',', '.', $clean);
        }

        return (int) round(((float) $clean) * 100);
    }

    /**
     * Centimos -> string decimal com 2 casas ("1250" -> "12.50").
     * Serve para pre-preencher inputs; a formatacao com simbolo e
     * separadores PT e feita no frontend (resources/js/lib/money.ts).
     */
    public static function toDecimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
