<?php

namespace App\Support;

/**
 * Um dos tres precos sugeridos (Minimo, Recomendado, Premium), por unidade.
 *
 * Em MICROS, como o PricingResult. O preco vem com IVA e ja com a terminacao
 * ,49 / ,99; o custo, o lucro e as margens sao sem IVA.
 *
 * O `rule` diz qual das tres regras ganhou — e a primeira coisa a perguntar
 * quando um preco parece alto: se foi a margem, a hora de impressora ou o
 * lucro minimo do trabalho.
 */
final readonly class PriceTier
{
    public const MINIMUM = 'minimum';

    public const RECOMMENDED = 'recommended';

    public const PREMIUM = 'premium';

    /** Pela ordem em que os precos sobem. */
    public const NAMES = [self::MINIMUM, self::RECOMMENDED, self::PREMIUM];

    /** A margem de sempre sobre o custo (via preco de revenda). */
    public const RULE_MARGIN = 'margin';

    /** Custo + horas de impressao x lucro por hora do nivel. */
    public const RULE_PER_HOUR = 'per_hour';

    /** Custo + lucro minimo do trabalho, a dividir pelas pecas. So orcamentos. */
    public const RULE_MIN_JOB = 'min_job';

    public function __construct(
        public string $name,
        /** Com IVA, ja arredondado para ,49 / ,99. */
        public int $priceMicros,
        public int $priceExVatMicros,
        /** O custo deste nivel: o de producao, mais o design no Premium. */
        public int $costMicros,
        /** O design por unidade, so no Premium. Ja esta dentro do custo. */
        public int $designCostMicros,
        /** A mao de obra por unidade, para o lucro por hora "com trabalho". */
        public int $laborCostMicros,
        /** Horas de impressora por unidade, em minutos x 1000 (precisao). */
        public int $printMilliMinutes,
        public string $rule,
        /**
         * Os candidatos sem IVA de cada regra, antes de arredondar. Null onde a
         * regra nao se aplica (o lucro minimo fora dos orcamentos).
         *
         * @var array{margin: int, per_hour: int, min_job: int|null}
         */
        public array $candidatesExVatMicros,
        /** True quando subiu para nao empatar com o nivel de baixo. */
        public bool $raisedForOrder,
    ) {}

    public function vatMicros(): int
    {
        return $this->priceMicros - $this->priceExVatMicros;
    }

    public function profitMicros(): int
    {
        return $this->priceExVatMicros - $this->costMicros;
    }

    public function marginBp(): int
    {
        return $this->priceExVatMicros === 0
            ? 0
            : Micros::divRound($this->profitMicros() * Rate::PER_UNIT, $this->priceExVatMicros);
    }

    /** Lucro por hora de impressora: o lucro limpo, depois de pagar tudo. */
    public function profitPerPrintHourMicros(): int
    {
        return $this->perPrintHour($this->profitMicros());
    }

    /**
     * O que fica para mim por hora de impressora, contando o meu trabalho e o
     * design (que estao no custo como mao de obra, mas sao dinheiro que me
     * paga a mim).
     */
    public function earningsPerPrintHourMicros(): int
    {
        return $this->perPrintHour($this->profitMicros() + $this->laborCostMicros + $this->designCostMicros);
    }

    private function perPrintHour(int $micros): int
    {
        // micros / (milliMinutes / 60000) — uma divisao so.
        return $this->printMilliMinutes <= 0
            ? 0
            : Micros::divRound($micros * 60_000, $this->printMilliMinutes);
    }
}
