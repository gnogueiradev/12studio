<?php

namespace App\Support;

/**
 * Contraste entre duas cores pela formula da WCAG 2.x: 1 (iguais) a 21
 * (preto sobre branco). Texto corrido pede 4,5; titulos e botoes, 3.
 */
final class Contrast
{
    public const AA_TEXT = 4.5;

    public const AA_LARGE = 3.0;

    public static function ratio(string $a, string $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);

        return round((max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05), 2);
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(
            fn (string $pair): float => hexdec($pair) / 255,
            str_split(substr(str_pad($hex, 6, '0'), 0, 6), 2),
        );

        [$r, $g, $b] = array_map(
            fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
