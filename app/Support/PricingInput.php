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
        // Por peca, sempre — nunca se dividem pela mesa.
        public int $packagingCostCents = 0,
        public int $componentsCostCents = 0,
        /** Null = usa a definicao global. Zero e diferente: e "nao leva trabalho". */
        public ?int $activeLaborMinutes = null,
        public int $quantity = 1,
        public ?int $printerProfileId = null,
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
