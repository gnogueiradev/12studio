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
 * parcelas intermedias — 0,765 EUR de filamento, 0,06325 EUR de reserva — nao
 * cabem num centimo, e arredonda-las desviava o preco final.
 *
 * A MARGEM e um multiplicador so, aplicado ao custo real. Ja aqui houve uma
 * tabela progressiva por faixa de custo; saiu porque fazia o preco saltar ao
 * atravessar um limiar (dois centimos de custo a mais podiam BAIXAR o preco) e
 * porque, somada a um custo de maquina alto, dava precos que nao competiam.
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
        // nenhuma. E daqui que sai o 0,765 EUR do caso de referencia.
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

        $multiplierBp = $this->settings->resaleMultiplierBp();

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
     * Fora de lote e um custo FIXO, e nao uma tabela por peso. O que cresce com
     * o tamanho da peca e o tempo de impressao, que ja se cobra a parte —
     * cobrar tambem manuseamento a subir era pagar duas vezes pela mesma coisa.
     *
     * Em lote nao se usa esse custo fixo: o trabalho decompoe-se no que se faz
     * UMA vez (montar a mesa, tirar a placa) e no que se faz a CADA peca
     * (rebarbar, ensacar) — uma diluicao que um valor por peca nao exprime.
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

        return Micros::fromCents($this->settings->handlingCostCents());
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
