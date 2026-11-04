<?php

namespace App\Support;

/**
 * Um preco escolhido a mao, posto ao lado do calculo: quanto deixa, e onde cai
 * face aos tres niveis sugeridos.
 *
 * So informa. Um preco abaixo do Minimo e uma decisao comercial legitima (o
 * mercado da Etsy pode estar abaixo do que a peca devia render), e quem a toma
 * precisa de ver o lucro por hora que fica — nao de ser travado.
 *
 * Por UNIDADE, como os niveis. O custo e o de producao: o design so existe no
 * Premium, e um preco escolhido nao e o Premium. Em MICROS; os centimos so nos
 * toArray().
 */
final readonly class PriceEvaluation
{
    public const BELOW_MINIMUM = 'below_minimum';

    public const MINIMUM_TO_RECOMMENDED = 'minimum_to_recommended';

    public const RECOMMENDED_TO_PREMIUM = 'recommended_to_premium';

    public const ABOVE_PREMIUM = 'above_premium';

    public function __construct(
        /** Com IVA, tal como foi escrito. */
        public int $priceMicros,
        public int $priceExVatMicros,
        public int $costMicros,
        /** Horas de impressora por unidade, em minutos x 1000 (como o PriceTier). */
        public int $printMilliMinutes,
        public int $quantity,
        /** Um dos quatro intervalos: abaixo do Minimo, entre niveis, acima do Premium. */
        public string $position,
        /** O nivel com exatamente este preco, se houver. */
        public ?string $matchesTier,
        public int $minimumPriceMicros,
    ) {}

    public static function of(PricingResult $result, int $priceCents): self
    {
        $price = Micros::fromCents(max(0, $priceCents));
        $tiers = $result->tiers;

        $position = match (true) {
            $price < $tiers->minimum->priceMicros => self::BELOW_MINIMUM,
            $price < $tiers->recommended->priceMicros => self::MINIMUM_TO_RECOMMENDED,
            $price <= $tiers->premium->priceMicros => self::RECOMMENDED_TO_PREMIUM,
            default => self::ABOVE_PREMIUM,
        };

        $matches = null;
        foreach ($tiers->all() as $name => $tier) {
            if ($tier->priceMicros === $price) {
                $matches = $name;
            }
        }

        return new self(
            priceMicros: $price,
            priceExVatMicros: $result->exVat($price),
            costMicros: $result->productionCostMicros,
            printMilliMinutes: $tiers->minimum->printMilliMinutes,
            quantity: $result->quantity,
            position: $position,
            matchesTier: $matches,
            minimumPriceMicros: $tiers->minimum->priceMicros,
        );
    }

    public function isBelowMinimum(): bool
    {
        return $this->position === self::BELOW_MINIMUM;
    }

    /** Pode ser negativo: um preco abaixo do custo. */
    public function profitMicros(): int
    {
        return $this->priceExVatMicros - $this->costMicros;
    }

    public function marginBp(): int
    {
        return $this->priceExVatMicros === 0
            ? 0
            : Micros::divRoundSigned($this->profitMicros() * Rate::PER_UNIT, $this->priceExVatMicros);
    }

    /** Em lote, a hora de impressora ja vem dividida pelas pecas da mesa. */
    public function profitPerPrintHourMicros(): int
    {
        return $this->printMilliMinutes <= 0
            ? 0
            : Micros::divRoundSigned($this->profitMicros() * 60_000, $this->printMilliMinutes);
    }

    /**
     * O aviso de preco abaixo do Minimo — o unico que ha. Informativo.
     *
     * @return list<array{code: string, message: string}>
     */
    public function warnings(): array
    {
        if (! $this->isBelowMinimum()) {
            return [];
        }

        return [[
            'code' => 'price_below_minimum',
            'message' => 'O preço '.self::eur($this->priceMicros).' está abaixo do Mínimo sugerido ('.self::eur($this->minimumPriceMicros).'). Só informativo: o lucro por hora de impressora fica em '.self::eur($this->profitPerPrintHourMicros()).'.',
        ]];
    }

    /**
     * Para o Inertia, em centimos.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'priceCents' => self::cents($this->priceMicros),
            'priceExVatCents' => self::cents($this->priceExVatMicros),
            'vatCents' => self::cents($this->priceMicros) - self::cents($this->priceExVatMicros),
            'costCents' => self::cents($this->costMicros),
            'profitCents' => self::cents($this->profitMicros()),
            'jobProfitCents' => self::cents($this->profitMicros() * $this->quantity),
            'marginBp' => $this->marginBp(),
            'profitPerPrintHourCents' => self::cents($this->profitPerPrintHourMicros()),
            'position' => $this->position,
            'matchesTier' => $this->matchesTier,
            'belowMinimum' => $this->isBelowMinimum(),
            'warnings' => $this->warnings(),
        ];
    }

    /** Centimos com sinal: um lucro negativo arredonda como um positivo. */
    public static function cents(int $micros): int
    {
        return Micros::divRoundSigned($micros, Micros::PER_CENT);
    }

    private static function eur(int $micros): string
    {
        return number_format(self::cents($micros) / 100, 2, ',', '').' €';
    }
}
