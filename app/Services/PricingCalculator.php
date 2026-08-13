<?php

namespace App\Services;

use App\Support\Micros;
use App\Support\PricingInput;
use App\Support\PricingResult;

/**
 * O custo real de imprimir uma peca, e por quanto vende-la.
 *
 * A versao anterior deste calculo (docs/plano.md, nunca implementada) partia da
 * gramagem. Duas pecas de 32 g podem demorar 30 minutos ou 4 horas: o custo do
 * material e igual e o custo real de producao nao e. A partir daqui o TEMPO DE
 * IMPRESSAO e input obrigatorio, e o preco sai de
 *
 *     MATERIAL + TEMPO DE MAQUINA + MANUSEAMENTO + RISCO + EXTRAS + MARGEM
 *
 * Toda a aritmetica corre em micro-euros inteiros (App\Support\Micros): as
 * parcelas intermedias — 0,544 EUR de filamento, 0,10352 EUR de reserva — nao
 * cabem num centimo, e arredonda-las desviava o preco final.
 *
 * Os parametros vem do PricingSettings (config/pricing.php + tabela settings);
 * o custo/hora vem do perfil de impressora e entra por argumento.
 */
class PricingCalculator
{
    /**
     * Degrau do preco de revenda: 0,50 EUR, sempre para CIMA. Regra comercial
     * fixa, nao definicao — a lista de precos e 1,50 / 2,00 / 2,50 e por ai
     * fora, e um 3,30 EUR no meio nao pertence a lista nenhuma.
     */
    private const RESALE_STEP = 500_000;

    /** Degraus do arredondamento do preco ao cliente, por faixa. */
    private const RETAIL_STEP_UNDER_20 = 500_000;

    private const RETAIL_STEP_UNDER_50 = 1_000_000;

    private const RETAIL_STEP_ABOVE_50 = 5_000_000;

    public function __construct(
        private PricingSettings $settings,
    ) {}

    public function calculate(PricingInput $input): PricingResult
    {
        // Exato por construcao: um centimo sao 10.000 micros e um kg sao 1000 g,
        // por isso o /1000 do preco por kg dissolve-se na constante (10000/1000
        // = 10) e este passo — o mais sensivel de todos — nao tem divisao
        // nenhuma. E daqui que sai o 0,544 EUR do caso de referencia.
        $filament = $input->weightGrams * $input->pricePerKgCents * 10;

        $machine = Micros::divRound(
            $input->minutes * $input->hourlyRateCents * Micros::PER_CENT,
            60,
        );

        $handling = $this->handling($input);

        // A reserva de falha aplica-se so sobre filamento + maquina. Os custos
        // extra ficam DE FORA de proposito: um ima ou uma caixa entram depois
        // da impressao, e uma impressao falhada nao os consome.
        $reserve = Micros::applyBp($filament + $machine, $this->settings->failureReserveBp());

        $extra = Micros::fromCents($input->extraCostCents);

        $jobCost = $filament + $machine + $handling + $reserve + $extra;

        $divisor = $input->costDivisor();
        $unitCost = Micros::divRound($jobCost, $divisor);

        $multiplierBp = $this->resaleMultiplierBp($unitCost);

        $rawResale = max(
            Micros::applyBp($unitCost, $multiplierBp),
            Micros::fromCents($this->settings->minimumResalePriceCents()),
        );

        $resale = Micros::ceilTo($rawResale, self::RESALE_STEP);

        $rawRetail = Micros::applyBp($resale, $this->settings->retailMultiplierBp());
        $retail = Micros::roundHalfUpTo($rawRetail, self::retailStep($rawRetail));

        // Protecao da margem do revendedor: o arredondamento comercial tanto
        // sobe como desce, e uma descida pode deixar quem revende abaixo do
        // markup minimo. Nesse caso sobe-se ao proximo preco valido — nunca se
        // baixa o preco de revenda para compensar.
        $minimumRetail = Micros::applyBp($resale, $this->settings->minimumRetailMultiplierBp());
        $bumped = $retail < $minimumRetail;

        if ($bumped) {
            $retail = self::nextCommercialPrice($minimumRetail);
        }

        return new PricingResult(
            mode: $input->mode,
            quantity: max(1, $input->quantity),
            filamentCostMicros: Micros::divRound($filament, $divisor),
            machineCostMicros: Micros::divRound($machine, $divisor),
            handlingCostMicros: Micros::divRound($handling, $divisor),
            failureReserveMicros: Micros::divRound($reserve, $divisor),
            extraCostMicros: Micros::divRound($extra, $divisor),
            productionCostMicros: $unitCost,
            resaleMultiplierBp: $multiplierBp,
            rawResalePriceMicros: $rawResale,
            resalePriceMicros: $resale,
            rawRetailPriceMicros: $rawRetail,
            minimumRetailPriceMicros: $minimumRetail,
            retailPriceMicros: $retail,
            retailBumped: $bumped,
        );
    }

    /**
     * Manuseamento: o trabalho que existe independentemente das horas da
     * maquina — preparar o ficheiro, tirar a peca da mesa, verificar, limpar,
     * separar suportes, embalar.
     *
     * Em LOTE a tabela por peso nao se usa de todo. Ela e calibrada por PECA, e
     * o peso total de uma mesa nao diz nada sobre o trabalho de a limpar: 10
     * porta-chaves de 30 g cairiam na faixa dos 300 g (0,50 EUR no trabalho
     * todo, 0,05 EUR por peca) — menos do que uma unica peca de 30 g. Em lote o
     * custo decompoe-se no que se faz UMA vez e no que se faz a CADA peca.
     *
     * Consequencia assumida: um lote de uma peca paga mais do que a mesma peca
     * em modo unitario. Esta certo — um lote de um ainda paga uma montagem.
     */
    private function handling(PricingInput $input): int
    {
        if ($input->isBatch()) {
            return Micros::fromCents(
                $this->settings->batchJobHandlingCents()
                + $this->settings->batchUnitHandlingCents() * max(1, $input->quantity),
            );
        }

        foreach ($this->settings->handlingTiers() as $tier) {
            if ($tier['threshold'] === null || $input->weightGrams <= $tier['threshold']) {
                return Micros::fromCents($tier['value']);
            }
        }

        return 0; // inalcancavel: a ultima faixa e sempre aberta (PricingSettings valida)
    }

    /**
     * Margem progressiva: quanto menor o custo, maior o multiplicador. Sem
     * isto, objetos pequenos vendiam-se com um lucro que nao paga o tempo de os
     * embalar.
     */
    private function resaleMultiplierBp(int $costMicros): int
    {
        foreach ($this->settings->resaleMultipliers() as $tier) {
            if ($tier['threshold'] === null || $costMicros <= Micros::fromCents($tier['threshold'])) {
                return $tier['value'];
            }
        }

        return 10_000; // inalcancavel: a ultima faixa e sempre aberta
    }

    /**
     * Degrau do preco ao cliente, pela faixa em que o valor CRU cai. Abaixo de
     * 20 EUR le-se em meias-unidades (6,50), ate 50 EUR no euro redondo, e daí
     * para cima aos 5 EUR — ninguem anuncia uma peca a 63 EUR.
     */
    private static function retailStep(int $micros): int
    {
        return match (true) {
            $micros < 20 * Micros::PER_EURO => self::RETAIL_STEP_UNDER_20,
            $micros <= 50 * Micros::PER_EURO => self::RETAIL_STEP_UNDER_50,
            default => self::RETAIL_STEP_ABOVE_50,
        };
    }

    /**
     * O menor preco comercialmente valido >= $floor.
     *
     * "Valido" = multiplo do degrau DA SUA PROPRIA faixa. Arredondar para cima
     * dentro de uma faixa pode atirar o valor para a seguinte (19,80 -> 20,00),
     * por isso testam-se as faixas por ordem e ganha a primeira onde o
     * candidato ainda cabe. Nao ha ciclo: as faixas sao crescentes e a ultima
     * nao tem tecto, portanto o terceiro candidato e sempre resposta.
     */
    private static function nextCommercialPrice(int $floor): int
    {
        $half = Micros::ceilTo($floor, self::RETAIL_STEP_UNDER_20);

        if ($half < 20 * Micros::PER_EURO) {
            return $half;
        }

        $euro = Micros::ceilTo($floor, self::RETAIL_STEP_UNDER_50);

        if ($euro <= 50 * Micros::PER_EURO) {
            return $euro;
        }

        return Micros::ceilTo($floor, self::RETAIL_STEP_ABOVE_50);
    }
}
