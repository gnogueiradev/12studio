<?php

namespace App\Support;

/**
 * O resultado de um calculo de preco, com tudo o que o painel "Ver calculo
 * detalhado" precisa de mostrar.
 *
 * Todos os campos em MICROS (1/1.000.000 EUR) menos as taxas, que estao em
 * pontos base. Os centimos so aparecem nos acessores — arredondar cedo era
 * exatamente o que esta classe existe para evitar. Ver App\Support\Micros.
 */
final readonly class PricingResult
{
    public function __construct(
        public string $mode,
        public int $quantity,
        // Parcelas do custo, ja por unidade.
        public int $filamentCostMicros,
        public int $machineCostMicros,
        public int $handlingCostMicros,
        public int $failureReserveMicros,
        public int $extraCostMicros,
        public int $productionCostMicros,
        // Revenda.
        public int $resaleMultiplierBp,
        public int $rawResalePriceMicros,
        public int $resalePriceMicros,
        // Cliente final.
        public int $rawRetailPriceMicros,
        public int $minimumRetailPriceMicros,
        public int $retailPriceMicros,
        /** True quando a protecao da margem do revendedor subiu o preco. */
        public bool $retailBumped,
    ) {}

    public function producerProfitMicros(): int
    {
        return $this->resalePriceMicros - $this->productionCostMicros;
    }

    public function resellerProfitMicros(): int
    {
        return $this->retailPriceMicros - $this->resalePriceMicros;
    }

    /** Margem sobre a VENDA (definicao oficial do plano), em pontos base. */
    public function producerMarginBp(): int
    {
        return $this->resalePriceMicros === 0
            ? 0
            : Micros::divRound($this->producerProfitMicros() * 10_000, $this->resalePriceMicros);
    }

    public function resellerMarginBp(): int
    {
        return $this->retailPriceMicros === 0
            ? 0
            : Micros::divRound($this->resellerProfitMicros() * 10_000, $this->retailPriceMicros);
    }

    /** Markup: lucro sobre o CUSTO do revendedor, nao sobre a venda. */
    public function resellerMarkupBp(): int
    {
        return $this->resalePriceMicros === 0
            ? 0
            : Micros::divRound($this->resellerProfitMicros() * 10_000, $this->resalePriceMicros);
    }

    /**
     * O payload que vai para o Inertia. Micros E centimos: os primeiros para o
     * calculo detalhado (0,10352 EUR nao cabe num centimo), os segundos para os
     * numeros grandes e para o botao "Aplicar precos".
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'quantity' => $this->quantity,

            'filamentCostMicros' => $this->filamentCostMicros,
            'machineCostMicros' => $this->machineCostMicros,
            'handlingCostMicros' => $this->handlingCostMicros,
            'failureReserveMicros' => $this->failureReserveMicros,
            'extraCostMicros' => $this->extraCostMicros,
            'productionCostMicros' => $this->productionCostMicros,

            'resaleMultiplierBp' => $this->resaleMultiplierBp,
            'rawResalePriceMicros' => $this->rawResalePriceMicros,
            'rawRetailPriceMicros' => $this->rawRetailPriceMicros,
            'minimumRetailPriceMicros' => $this->minimumRetailPriceMicros,
            'retailBumped' => $this->retailBumped,

            'productionCostCents' => Micros::toCents($this->productionCostMicros),
            'resalePriceCents' => Micros::toCents($this->resalePriceMicros),
            'retailPriceCents' => Micros::toCents($this->retailPriceMicros),
            'producerProfitCents' => Micros::toCents($this->producerProfitMicros()),
            'resellerProfitCents' => Micros::toCents($this->resellerProfitMicros()),

            'producerMarginBp' => $this->producerMarginBp(),
            'resellerMarginBp' => $this->resellerMarginBp(),
            'resellerMarkupBp' => $this->resellerMarkupBp(),

            // Totais do trabalho = unidade x quantidade, nos dois modos.
            'job' => [
                'productionCostCents' => Micros::toCents($this->productionCostMicros * $this->quantity),
                'resalePriceCents' => Micros::toCents($this->resalePriceMicros * $this->quantity),
                'retailPriceCents' => Micros::toCents($this->retailPriceMicros * $this->quantity),
            ],
        ];
    }
}
