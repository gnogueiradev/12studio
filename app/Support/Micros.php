<?php

namespace App\Support;

/**
 * Aritmetica de precos em MICRO-EUROS: inteiros de 1/1.000.000 EUR.
 *
 * A regra do projeto e "centimos inteiros, nunca floats" (App\Support\Money),
 * mas a formula de custo encadeia cinco multiplicacoes antes de haver um preco
 * para mostrar: 0,765 EUR de filamento e 0,06325 EUR de reserva de falha nao
 * cabem num centimo, e arredondar cada passo desviava o preco final. O micro
 * tem quatro casas a mais do que o centimo e continua a ser inteiro — o custo
 * de referencia, 1,47825 EUR, e exatamente 1478250.
 *
 * Todos os metodos assumem valores NAO NEGATIVOS. A validacao dos formularios
 * garante min:0 nos custos e min:1 nos multiplicadores; com negativos o
 * arredondamento ao meio para cima deixa de ser simetrico.
 */
final class Micros
{
    public const PER_EURO = 1_000_000;

    public const PER_CENT = 10_000;

    public static function fromCents(int $cents): int
    {
        return $cents * self::PER_CENT;
    }

    /**
     * Micros -> centimos. O unico sitio onde se perde precisao, e so para
     * mostrar ou para gravar num campo *_cents.
     */
    public static function toCents(int $micros): int
    {
        return self::divRound($micros, self::PER_CENT);
    }

    /**
     * Divisao inteira com arredondamento ao meio para CIMA.
     *
     * O intdiv sozinho corta sempre para baixo, e o erro de cinco divisoes
     * seguidas acumulava-se todo no mesmo sentido. Quando a divisao e exata o
     * +metade nunca empurra o resultado para cima: (2n+d)/(2d) = k+0,5 e o
     * intdiv corta esse meio.
     */
    public static function divRound(int $value, int $divisor): int
    {
        return intdiv(2 * $value + $divisor, 2 * $divisor);
    }

    /**
     * O divRound() para valores que podem ser NEGATIVOS — um lucro abaixo do
     * custo. Arredonda o valor absoluto e repoe o sinal: o intdiv corta para
     * zero, e com um negativo o "+metade" do divRound() empurrava-o para o
     * lado errado (-1,6 dava -1). Divisor sempre positivo.
     */
    public static function divRoundSigned(int $value, int $divisor): int
    {
        return $value < 0 ? -self::divRound(-$value, $divisor) : self::divRound($value, $divisor);
    }

    /**
     * Taxa em pontos base: 10000 bp = 100%, 800 bp = 8%, 17500 bp = 1,75x.
     * Pontos base e nao percentagem inteira para caber um 7,5% de reserva de
     * falha sem mudar de tipo.
     */
    public static function applyBp(int $micros, int $bp): int
    {
        return self::divRound($micros * $bp, 10_000);
    }

    /**
     * Proximo multiplo de $step, para CIMA. Idempotente num multiplo exato:
     * 3,50 EUR ja e um degrau de 0,50 e nao pode saltar para 4,00.
     */
    public static function ceilTo(int $micros, int $step): int
    {
        return intdiv($micros + $step - 1, $step) * $step;
    }

    /**
     * Para CIMA, ate ao proximo preco terminado em ,49 ou ,99. Idempotente:
     * 11,49 EUR fica 11,49; 11,50 sobe a 11,99; 11,00 sobe a 11,49.
     *
     * Primeiro ao centimo (para cima — nem um micro se perde), depois ao
     * proximo centimo c com (c + 1) multiplo de 50.
     */
    public static function ceilToEnding(int $micros): int
    {
        $cents = intdiv(max(0, $micros) + self::PER_CENT - 1, self::PER_CENT);

        return (intdiv($cents + 1 + 49, 50) * 50 - 1) * self::PER_CENT;
    }

    /** A terminacao ,49 / ,99 seguinte, estritamente acima. */
    public static function nextEnding(int $micros): int
    {
        return self::ceilToEnding(self::ceilToEnding($micros) + self::PER_CENT);
    }

    /**
     * Euros escritos por humanos -> micros. Gemeo do Money::fromDecimal, mas
     * com quatro casas a mais.
     *
     * Existe porque ha dois parametros do negocio que nao cabem num centimo: a
     * tarifa da eletricidade (0,1420 EUR/kWh) e a manutencao a hora (0,0400
     * EUR/h). Guardados em centimos viravam 14 e 4, e o custo de energia de uma
     * peca de tres horas saia 4% ao lado. A leitura do numero — virgula ou
     * ponto, com ou sem simbolo — continua a ser a do Money.
     */
    public static function fromDecimal(string $value): int
    {
        return (int) round(Money::normalizeDecimal($value) * self::PER_EURO);
    }

    /**
     * Micros -> string decimal para pre-preencher um input.
     *
     * Quatro casas por omissao porque e essa a precisao que os campos que
     * precisam desta classe mostram; quem so quer euros passa 2. A formatacao
     * com simbolo e separadores PT continua a ser do frontend.
     */
    public static function toDecimal(int $micros, int $decimals = 4): string
    {
        return number_format($micros / self::PER_EURO, $decimals, '.', '');
    }
}
