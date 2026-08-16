<?php

namespace App\Support;

/**
 * O resultado de um calculo de preco, com tudo o que a calculadora precisa de
 * mostrar: quanto custa cada parcela, por quanto se vende e quanto sobra —
 * para mim e para quem me revende.
 *
 * Todos os campos em MICROS (1/1.000.000 EUR) menos as taxas, que estao em
 * pontos base. Os centimos so aparecem nos acessores — arredondar cedo era
 * exatamente o que esta classe existe para evitar. Ver App\Support\Micros.
 *
 * As margens sao todas sobre a VENDA (lucro / preco), e nao sobre o custo. A
 * unica excepcao esta la a dizer que e: o markup do revendedor.
 */
final readonly class PricingResult
{
    public function __construct(
        public string $mode,
        public int $quantity,
        /** Minutos de trabalho humano do trabalho todo, para o painel explicar a conta. */
        public int $laborMinutes,
        // As sete parcelas do custo, ja por unidade.
        public int $filamentCostMicros,
        public int $electricityCostMicros,
        public int $depreciationCostMicros,
        public int $maintenanceCostMicros,
        public int $laborCostMicros,
        public int $packagingCostMicros,
        public int $componentsCostMicros,
        /** A soma das sete, antes do risco de falhas. */
        public int $baseProductionCostMicros,
        /** O subtotal depois de dividido por (1 - taxa de falhas). */
        public int $productionCostMicros,
        // Revenda.
        public int $rawWholesalePriceMicros,
        public int $wholesalePriceMicros,
        // Cliente final.
        public int $rawRetailPriceMicros,
        public int $retailPriceMicros,
        /** Comissoes do canal sobre o preco ao cliente. Fora do custo. */
        public int $channelFeeMicros,
        public int $failureRateBp,
        public int $targetWholesaleMarginBp,
        public int $targetResellerMarginBp,
    ) {}

    /**
     * Quanto o risco de falhas acrescentou. E uma diferenca e nao uma parcela
     * calculada a parte, porque e mesmo isso que ele e: o custo real menos o
     * que a peca teria custado se nunca falhasse nada.
     */
    public function failureCostMicros(): int
    {
        return $this->productionCostMicros - $this->baseProductionCostMicros;
    }

    /*
     * O meu lucro, nas duas vendas.
     */

    public function wholesaleProfitMicros(): int
    {
        return $this->wholesalePriceMicros - $this->productionCostMicros;
    }

    public function wholesaleMarginBp(): int
    {
        return self::marginBp($this->wholesaleProfitMicros(), $this->wholesalePriceMicros);
    }

    /** Vender eu proprio ao cliente: mesmo preco publico, o meu custo. */
    public function directProfitMicros(): int
    {
        return $this->retailPriceMicros - $this->productionCostMicros;
    }

    public function directMarginBp(): int
    {
        return self::marginBp($this->directProfitMicros(), $this->retailPriceMicros);
    }

    /** O que sobra da venda direta depois das comissoes do canal. */
    public function netDirectProfitMicros(): int
    {
        return $this->directProfitMicros() - $this->channelFeeMicros;
    }

    public function netDirectMarginBp(): int
    {
        return self::marginBp($this->netDirectProfitMicros(), $this->retailPriceMicros);
    }

    /*
     * O lucro de quem me compra para revender.
     */

    public function resellerProfitMicros(): int
    {
        return $this->retailPriceMicros - $this->wholesalePriceMicros;
    }

    public function resellerMarginBp(): int
    {
        return self::marginBp($this->resellerProfitMicros(), $this->retailPriceMicros);
    }

    /** Markup: lucro sobre o CUSTO do revendedor, nao sobre a venda. */
    public function resellerMarkupBp(): int
    {
        return self::marginBp($this->resellerProfitMicros(), $this->wholesalePriceMicros);
    }

    /**
     * O payload que vai para o Inertia. Micros E centimos: os primeiros para o
     * calculo detalhado (0,06177 EUR de eletricidade nao cabe num centimo), os
     * segundos para os numeros grandes e para o botao "Aplicar precos".
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'quantity' => $this->quantity,
            'laborMinutes' => $this->laborMinutes,

            'filamentCostMicros' => $this->filamentCostMicros,
            'electricityCostMicros' => $this->electricityCostMicros,
            'depreciationCostMicros' => $this->depreciationCostMicros,
            'maintenanceCostMicros' => $this->maintenanceCostMicros,
            'laborCostMicros' => $this->laborCostMicros,
            'packagingCostMicros' => $this->packagingCostMicros,
            'componentsCostMicros' => $this->componentsCostMicros,
            'baseProductionCostMicros' => $this->baseProductionCostMicros,
            'failureCostMicros' => $this->failureCostMicros(),
            'productionCostMicros' => $this->productionCostMicros,

            'rawWholesalePriceMicros' => $this->rawWholesalePriceMicros,
            'wholesalePriceMicros' => $this->wholesalePriceMicros,
            'rawRetailPriceMicros' => $this->rawRetailPriceMicros,
            'retailPriceMicros' => $this->retailPriceMicros,
            'channelFeeMicros' => $this->channelFeeMicros,

            'failureRateBp' => $this->failureRateBp,
            'targetWholesaleMarginBp' => $this->targetWholesaleMarginBp,
            'targetResellerMarginBp' => $this->targetResellerMarginBp,

            'productionCostCents' => Micros::toCents($this->productionCostMicros),
            'wholesalePriceCents' => Micros::toCents($this->wholesalePriceMicros),
            'retailPriceCents' => Micros::toCents($this->retailPriceMicros),
            'channelFeeCents' => Micros::toCents($this->channelFeeMicros),
            'wholesaleProfitCents' => Micros::toCents($this->wholesaleProfitMicros()),
            'directProfitCents' => Micros::toCents($this->directProfitMicros()),
            'netDirectProfitCents' => Micros::toCents($this->netDirectProfitMicros()),
            'resellerProfitCents' => Micros::toCents($this->resellerProfitMicros()),

            'wholesaleMarginBp' => $this->wholesaleMarginBp(),
            'directMarginBp' => $this->directMarginBp(),
            'netDirectMarginBp' => $this->netDirectMarginBp(),
            'resellerMarginBp' => $this->resellerMarginBp(),
            'resellerMarkupBp' => $this->resellerMarkupBp(),

            // Totais do trabalho = unidade x quantidade, nos dois modos.
            'job' => [
                'productionCostCents' => Micros::toCents($this->productionCostMicros * $this->quantity),
                'wholesalePriceCents' => Micros::toCents($this->wholesalePriceMicros * $this->quantity),
                'retailPriceCents' => Micros::toCents($this->retailPriceMicros * $this->quantity),
                'wholesaleProfitCents' => Micros::toCents($this->wholesaleProfitMicros() * $this->quantity),
                'directProfitCents' => Micros::toCents($this->directProfitMicros() * $this->quantity),
                'netDirectProfitCents' => Micros::toCents($this->netDirectProfitMicros() * $this->quantity),
            ],
        ];
    }

    /**
     * Lucro / preco, em pontos base. Um preco a zero nao tem margem — tem uma
     * divisao por zero.
     */
    private static function marginBp(int $profit, int $price): int
    {
        return $price === 0 ? 0 : Micros::divRound($profit * 10_000, $price);
    }
}
