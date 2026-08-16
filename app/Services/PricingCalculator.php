<?php

namespace App\Services;

use App\Support\Micros;
use App\Support\PricingInput;
use App\Support\PricingResult;
use App\Support\Rate;

/**
 * O que custa produzir uma peca, e por quanto se vende.
 *
 * A cadeia e sempre a mesma:
 *
 *   filamento + eletricidade + depreciacao + manutencao + mao de obra
 *   + embalagem + componentes                                    = SUBTOTAL
 *   subtotal / (1 - taxa de falhas)                              = CUSTO REAL
 *   custo real / (1 - margem de revenda)   -> arredonda p/ cima  = REVENDA
 *   revenda / (1 - margem do revendedor)   -> arredonda p/ cima  = CLIENTE
 *
 * Duas coisas nesta formula sao contra-intuitivas e valem os dois paragrafos
 * que se seguem, porque ja aqui esteve a versao ingenua de ambas:
 *
 * 1. A TAXA DE FALHAS DIVIDE, NAO SOMA. Somar 5% (custo x 1,05) recupera menos
 *    do que se perdeu: a peca que falhou tambem gastou filamento, luz e horas
 *    de maquina, e o que se perde nela tem de ser recuperado nas que saem
 *    boas. Com 100 pecas e 5 falhadas, sao 95 a pagar o custo de 100 — logo
 *    /0,95, e nao x1,05. A diferenca no caso de referencia sao 5 milesimos de
 *    euro por peca, que a 500 pecas por ano deixa de ser arredondamento.
 *
 * 2. AS MARGENS SAO DECLARADAS, NAO DERIVADAS. Aqui viveram dois
 *    multiplicadores (1,70x, 1,75x) e ninguem sabia que margem e que davam —
 *    1,70x sobre o custo sao 41,2% de margem sobre a venda, o que so se
 *    descobre fazendo a conta ao contrario. Pedir a margem e dividir por
 *    (1 - margem) diz exatamente o que se queria dizer.
 *
 * O que NAO e configuravel, e vive aqui: o degrau de 0,50 EUR do preco de
 * revenda e as tres faixas de arredondamento do preco ao cliente. Sao regras
 * comerciais fixas — ninguem anuncia uma peca a 63,40 EUR.
 *
 * Os dois arredondamentos sao SEMPRE para cima. Ja foram ao mais proximo, e
 * arredondar para baixo comia a margem que se acabou de pedir — a ponto de ter
 * sido preciso uma rede de seguranca (um multiplicador minimo do revendedor)
 * para a apanhar. Com o `ceil` a rede deixou de ter o que apanhar e saiu.
 */
class PricingCalculator
{
    /** Degrau do preco de revenda: 0,50 EUR. */
    private const WHOLESALE_STEP = 500_000;

    private const RETAIL_STEP_UNDER_20 = 500_000;

    private const RETAIL_STEP_UNDER_50 = 1_000_000;

    private const RETAIL_STEP_ABOVE_50 = 5_000_000;

    public function __construct(
        private PricingSettings $settings,
    ) {}

    public function calculate(PricingInput $input): PricingResult
    {
        $divisor = $input->costDivisor();
        $quantity = max(1, $input->quantity);

        /*
         * Parcelas ao nivel do TRABALHO: em modo lote descrevem a mesa toda.
         *
         * Cada uma faz UMA divisao, no fim. A ordem nao e estilo: dividir a
         * tarifa por 1000 antes de multiplicar, ou calcular um EUR/h de
         * depreciacao intermedio, da o mesmo resultado nos numeros redondos do
         * caso de referencia e erra em qualquer outro — 400 EUR / 3000 h sao
         * 133_333,33 micros/h, e o terco perdido reaparece multiplicado pelas
         * horas da peca.
         */

        // Sem divisao nenhuma: um centimo sao 10.000 micros e um kg sao 1000 g,
        // logo o /1000 do preco por kg dissolve-se na constante (10000/1000).
        $filament = $input->weightGrams * $input->pricePerKgCents * 10;

        // Minutos a W watts sao (minutos/60) x (W/1000) kWh. As duas divisoes
        // fundem-se no 60_000.
        $electricity = Micros::divRound(
            $input->minutes * $input->printerPowerWatts * $this->settings->electricityPriceMicrosPerKwh(),
            60_000,
        );

        // A maquina paga-se a si propria nas pecas que faz. Guarda contra a
        // vida util a zero: a validacao do formulario poe min:1, mas uma
        // definicao escrita a mao por fora nao passa por validacao nenhuma.
        $depreciation = $input->printerLifetimeHours <= 0
            ? 0
            : Micros::divRound(
                $input->minutes * $input->printerPurchasePriceCents * Micros::PER_CENT,
                $input->printerLifetimeHours * 60,
            );

        $maintenance = Micros::divRound(
            $input->minutes * $input->printerMaintenanceMicrosPerHour,
            60,
        );

        $laborMinutes = $this->laborMinutes($input, $quantity);
        $labor = Micros::divRound(
            $laborMinutes * $this->settings->laborRateMicrosPerHour(),
            60,
        );

        /*
         * Parcelas ja POR UNIDADE. A embalagem e os componentes nao se dividem
         * pela mesa: doze pecas levam doze sacos e doze imanes.
         */
        $packaging = Micros::fromCents($input->packagingCostCents);
        $components = Micros::fromCents($input->componentsCostCents);

        $unitFilament = Micros::divRound($filament, $divisor);
        $unitElectricity = Micros::divRound($electricity, $divisor);
        $unitDepreciation = Micros::divRound($depreciation, $divisor);
        $unitMaintenance = Micros::divRound($maintenance, $divisor);
        $unitLabor = Micros::divRound($labor, $divisor);

        // O subtotal soma as parcelas JA DIVIDIDAS, e nao o total do trabalho a
        // dividir no fim. Sao quase a mesma coisa — diferem em micros — mas so
        // esta versao fecha a conta que o painel detalhado mostra linha a
        // linha. Um subtotal que nao bate certo com as parcelas por cima dele
        // faz duvidar do resto.
        $base = $unitFilament
            + $unitElectricity
            + $unitDepreciation
            + $unitMaintenance
            + $unitLabor
            + $packaging
            + $components;

        $failureRateBp = $this->failureRateBp();
        $production = Micros::divRound($base * Rate::PER_UNIT, Rate::PER_UNIT - $failureRateBp);

        $wholesaleMarginBp = $this->marginBp($this->settings->targetWholesaleMarginBp());
        $rawWholesale = max(
            Micros::divRound($production * Rate::PER_UNIT, Rate::PER_UNIT - $wholesaleMarginBp),
            // O chao aplica-se ANTES do arredondamento e ao custo da UNIDADE:
            // e ele que protege as pecas pequenas, nao a margem.
            Micros::fromCents($this->settings->minimumWholesalePriceCents()),
        );
        $wholesale = Micros::ceilTo($rawWholesale, self::WHOLESALE_STEP);

        // O preco ao cliente sai do preco de REVENDA, e nao do meu custo: e o
        // que garante que quem me compra para revender consegue mesmo viver da
        // diferenca. Vender eu proprio ao mesmo preco e uma escolha — nao ha
        // dois precos publicos para a mesma peca.
        $resellerMarginBp = $this->marginBp($this->settings->targetResellerMarginBp());
        $rawRetail = Micros::divRound($wholesale * Rate::PER_UNIT, Rate::PER_UNIT - $resellerMarginBp);
        $retail = Micros::ceilTo($rawRetail, self::retailStep($rawRetail));

        // Comissoes do canal: fora do custo industrial de proposito (nao custam
        // nada produzir), so no lucro liquido.
        $channelFee = Micros::fromCents($this->settings->salesChannelFixedFeeCents())
            + Micros::applyBp($retail, $this->settings->salesChannelPercentageFeeBp());

        return new PricingResult(
            mode: $input->mode,
            quantity: $quantity,
            laborMinutes: $laborMinutes,
            filamentCostMicros: $unitFilament,
            electricityCostMicros: $unitElectricity,
            depreciationCostMicros: $unitDepreciation,
            maintenanceCostMicros: $unitMaintenance,
            laborCostMicros: $unitLabor,
            packagingCostMicros: $packaging,
            componentsCostMicros: $components,
            baseProductionCostMicros: $base,
            productionCostMicros: $production,
            rawWholesalePriceMicros: $rawWholesale,
            wholesalePriceMicros: $wholesale,
            rawRetailPriceMicros: $rawRetail,
            retailPriceMicros: $retail,
            channelFeeMicros: $channelFee,
            failureRateBp: $failureRateBp,
            targetWholesaleMarginBp: $wholesaleMarginBp,
            targetResellerMarginBp: $resellerMarginBp,
        );
    }

    /**
     * Os minutos de trabalho humano do TRABALHO todo.
     *
     * Em lote o trabalho decompoe-se no que se faz UMA vez (montar a mesa,
     * lancar, tirar a placa) e no que se faz a CADA peca (rebarbar, limpar,
     * ensacar). E dai que vem a economia real de imprimir doze de uma vez: a
     * preparacao dilui-se, o acabamento nao.
     */
    private function laborMinutes(PricingInput $input, int $quantity): int
    {
        $active = $input->activeLaborMinutes ?? $this->settings->activeLaborMinutes();

        return $input->isBatch()
            ? $this->settings->setupLaborMinutes() + $active * $quantity
            : $active;
    }

    /**
     * A taxa de falhas, garantidamente dentro do dominio.
     *
     * A 100% o custo dividia-se por zero. O formulario ja poe um tecto de 50%,
     * mas uma chave `pricing.*` escrita a mao na tabela `settings` nao passa
     * por validacao nenhuma — e um erro de digitacao nao pode derrubar o
     * backoffice inteiro.
     */
    private function failureRateBp(): int
    {
        return max(0, min($this->settings->failureRateBp(), Rate::PER_UNIT - 1));
    }

    /** Mesma guarda, pelo mesmo motivo: preco = custo / (1 - margem). */
    private function marginBp(int $bp): int
    {
        return max(0, min($bp, Rate::PER_UNIT - 1));
    }

    private static function retailStep(int $micros): int
    {
        return match (true) {
            $micros < 20 * Micros::PER_EURO => self::RETAIL_STEP_UNDER_20,
            $micros <= 50 * Micros::PER_EURO => self::RETAIL_STEP_UNDER_50,
            default => self::RETAIL_STEP_ABOVE_50,
        };
    }
}
