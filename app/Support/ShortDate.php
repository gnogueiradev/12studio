<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * "09 ago" — a data curta das listagens do backoffice.
 *
 * Vive em Support porque duas listagens a usam: a coluna Data das encomendas e
 * a coluna Ultima compra dos clientes. A data completa viaja sempre a parte, no
 * `title` da celula, para quem precisa do ano e da hora.
 */
class ShortDate
{
    /** Abreviaturas PT dos meses, indexadas por numero do mes. */
    private const MONTHS = [
        1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 5 => 'mai', 6 => 'jun',
        7 => 'jul', 8 => 'ago', 9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez',
    ];

    /**
     * O mapa e explicito e nao um translatedFormat() porque o
     * config('app.locale') tem 'en' por omissao e o ambiente de testes nao
     * garante 'pt' — a data da listagem nao pode mudar de idioma consoante o
     * .env.
     */
    public static function of(?CarbonInterface $at): ?string
    {
        return $at === null
            ? null
            : $at->format('d').' '.self::MONTHS[(int) $at->format('n')];
    }
}
