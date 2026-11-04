<?php

namespace App\Support;

/**
 * Os dados de um calculo de preco.
 *
 * `final readonly` e nao array — o unico desvio deliberado a convencao do repo
 * (arrays para o que vai para o Eloquent). Estes dados atravessam quatro
 * fronteiras (pedido -> controlador -> calculador -> resultado) e o PHPStan
 * nivel 7 obrigava a repetir a mesma array{...} em cada uma.
 *
 * O peso e o tempo mudam de significado com o modo, e e so isso que os dois
 * modos tem de diferente:
 *   PER_UNIT  -> por unidade (o utilizador diz quanto pesa e quanto demora UMA)
 *   BATCH     -> do trabalho todo (o que o slicer diz para a mesa inteira)
 *
 * O que esta AQUI sao os factos: desta peca, deste filamento, desta maquina. O
 * que e politica da casa — tarifa da luz, valor da hora de trabalho, taxa de
 * falhas, margens — vive no PricingSettings e o calculador vai la busca-lo. A
 * divisao e essa: se muda de peca para peca, entra por aqui.
 */
final readonly class PricingInput
{
    /** O utilizador descreve UMA peca; a quantidade so multiplica no fim. */
    public const MODE_PER_UNIT = 'per_unit';

    /** O utilizador descreve a MESA toda; o custo divide-se pela quantidade. */
    public const MODE_BATCH = 'batch';

    /** Convencao qrcode: const arrays em vez de PHP enums. */
    public const MODES = [self::MODE_PER_UNIT, self::MODE_BATCH];

    public function __construct(
        public string $mode,
        public int $weightGrams,
        public int $minutes,
        public int $pricePerKgCents,
        // A maquina, em quatro numeros em vez de um custo/hora. Vem do perfil
        // de impressora escolhido, ou dos valores de recurso do config quando
        // nao ha nenhuma ativa. A tarifa da luz NAO esta aqui: e global.
        public int $printerPowerWatts,
        public int $printerPurchasePriceCents,
        public int $printerLifetimeHours,
        public int $printerMaintenanceMicrosPerHour,
        // O IVA da VENDA, em pontos base (2300 = 23%). E do produto, e por
        // isso entra por aqui e nao pelas definicoes: um livro e um vaso nao
        // pagam a mesma taxa. Sem valor por omissao de proposito — esquecer-se
        // dele era vender com IVA a zero sem ninguem dar por isso.
        public int $vatRateBp,
        // Por peca, sempre — nunca se dividem pela mesa.
        public int $packagingCostCents = 0,
        public int $componentsCostCents = 0,
        /** Null = usa a definicao global. Zero e diferente: e "nao leva trabalho". */
        public ?int $activeLaborMinutes = null,
        public int $quantity = 1,
        public ?int $printerProfileId = null,
        /*
         * ORCAMENTO (calculadora livre, pricing_calculate) ou peca de catalogo
         * (variante). So os orcamentos levam o minimo de trabalho, as placas,
         * as trocas de cor e o lucro minimo por trabalho: uma variante e uma
         * peca que se repete, e o custo dela e o que a Etsy e o travao de
         * precos do MCP leem — nao pode mudar por regras de trabalho por medida.
         */
        public bool $quote = false,
        /** Placas do trabalho: de UMA peca em per_unit, da mesa em batch. */
        public int $plates = 1,
        /** Trocas manuais de filamento, no mesmo ambito das placas. */
        public int $manualColorChanges = 0,
        /** Minutos de design do trabalho TODO: cobra-se uma vez, so no Premium. */
        public int $designMinutes = 0,
        /**
         * O custo do filamento que o slicer mostra, COM IVA (e como o Bambu
         * Studio o da), no mesmo ambito do peso. Null = nao ha com que comparar.
         */
        public ?int $referenceFilamentCostCents = null,
    ) {}

    public function isBatch(): bool
    {
        return $this->mode === self::MODE_BATCH;
    }

    /**
     * Por quanto se divide o custo do trabalho para chegar ao custo da unidade.
     *
     * E este numero que faz os dois modos colapsarem no mesmo codigo: em lote o
     * pipeline corre sobre a mesa e divide-se aqui; por unidade corre sobre a
     * peca e nao se divide nada. Dai para a frente — risco, margens,
     * arredondamentos — e tudo igual.
     */
    public function costDivisor(): int
    {
        return $this->isBatch() ? max(1, $this->quantity) : 1;
    }
}
