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
 *
 * O IVA separa os campos em dois grupos, e convem nao os misturar:
 *
 *   - os PRECOS (revenda, cliente) vem com IVA incluido — sao o que se grava
 *     na variante e o que a montra mostra;
 *   - os CUSTOS e os LUCROS sao sem IVA — o imposto que se cobra entrega-se, o
 *     que se paga deduz-se, e nenhum dos dois e dinheiro da loja.
 *
 * Quem faz a ponte sao os acessores `*ExVat*`. "Sem IVA" e nao "liquido" nos
 * nomes, de proposito: liquido ja quer dizer outra coisa aqui (o lucro depois
 * das comissoes do canal).
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
        /** O IVA da venda — o do produto — que os dois precos trazem dentro. */
        public int $vatRateBp,
        /** O IVA que foi tirado as parcelas compradas. Zero = ja vinham sem ele. */
        public int $costVatRateBp,
    ) {}

    /**
     * Um preco com IVA, sem ele. Publico porque nao serve so os dois precos
     * deste calculo: o preco que alguem escreveu a mao numa variante le-se com
     * a mesma taxa, e e isto que o travao do MCP compara com o custo.
     */
    public function exVat(int $micros): int
    {
        return Micros::divRound($micros * Rate::PER_UNIT, Rate::PER_UNIT + $this->vatRateBp);
    }

    public function wholesalePriceExVatMicros(): int
    {
        return $this->exVat($this->wholesalePriceMicros);
    }

    public function retailPriceExVatMicros(): int
    {
        return $this->exVat($this->retailPriceMicros);
    }

    /**
     * O IVA dentro do preco ao cliente. Uma diferenca e nao uma multiplicacao,
     * para as duas partes fecharem sempre o preco ao micro.
     */
    public function retailVatMicros(): int
    {
        return $this->retailPriceMicros - $this->retailPriceExVatMicros();
    }

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
     * O meu lucro, nas duas vendas. Sempre sobre o que fica depois de entregar
     * o IVA: e esse o dinheiro que entra, e e sobre ele que a margem se mede.
     */

    public function wholesaleProfitMicros(): int
    {
        return $this->wholesalePriceExVatMicros() - $this->productionCostMicros;
    }

    public function wholesaleMarginBp(): int
    {
        return self::marginBp($this->wholesaleProfitMicros(), $this->wholesalePriceExVatMicros());
    }

    /** Vender eu proprio ao cliente: mesmo preco publico, o meu custo. */
    public function directProfitMicros(): int
    {
        return $this->retailPriceExVatMicros() - $this->productionCostMicros;
    }

    public function directMarginBp(): int
    {
        return self::marginBp($this->directProfitMicros(), $this->retailPriceExVatMicros());
    }

    /** O que sobra da venda direta depois das comissoes do canal. */
    public function netDirectProfitMicros(): int
    {
        return $this->directProfitMicros() - $this->channelFeeMicros;
    }

    public function netDirectMarginBp(): int
    {
        return self::marginBp($this->netDirectProfitMicros(), $this->retailPriceExVatMicros());
    }

    /*
     * O lucro de quem me compra para revender.
     */

    /** Em euros, depois de ele entregar o IVA dele. */
    public function resellerProfitMicros(): int
    {
        return $this->retailPriceExVatMicros() - $this->wholesalePriceExVatMicros();
    }

    /**
     * A margem e o markup saem dos precos COM IVA, ao contrario de tudo o
     * resto nesta classe. Sao racios entre dois precos com a mesma taxa — o
     * IVA cai dos dois lados — e assim nao levam o micro de arredondamento de
     * o tirar a cada um. E o que mantem exata a garantia de que a margem do
     * revendedor nunca fica abaixo da pedida.
     */
    public function resellerMarginBp(): int
    {
        return self::marginBp($this->retailPriceMicros - $this->wholesalePriceMicros, $this->retailPriceMicros);
    }

    /** Markup: lucro sobre o CUSTO do revendedor, nao sobre a venda. */
    public function resellerMarkupBp(): int
    {
        return self::marginBp($this->retailPriceMicros - $this->wholesalePriceMicros, $this->wholesalePriceMicros);
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
            'vatRateBp' => $this->vatRateBp,
            'costVatRateBp' => $this->costVatRateBp,

            'productionCostCents' => Micros::toCents($this->productionCostMicros),
            'wholesalePriceCents' => Micros::toCents($this->wholesalePriceMicros),
            'retailPriceCents' => Micros::toCents($this->retailPriceMicros),
            // Os mesmos dois precos sem IVA, que e a receita das contas de
            // lucro. O IVA vai a parte e por diferenca dos CENTIMOS, para as
            // duas linhas que a pagina mostra somarem o preco que la esta.
            'wholesalePriceExVatCents' => Micros::toCents($this->wholesalePriceExVatMicros()),
            'retailPriceExVatCents' => Micros::toCents($this->retailPriceExVatMicros()),
            'retailVatCents' => Micros::toCents($this->retailPriceMicros) - Micros::toCents($this->retailPriceExVatMicros()),
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
