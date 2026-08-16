<?php

namespace App\Services;

use App\Support\Micros;
use App\Support\Money;
use App\Support\Rate;

/**
 * Os parametros da calculadora de precos: config/pricing.php por omissao, com
 * a tabela `settings` por cima assim que o admin lhes mexer.
 *
 * E aqui — e so aqui — que se sabe que definicoes de preco existem: os nomes
 * das chaves, os tipos, os valores por omissao e a traducao entre o que o
 * formulario mostra (percentagem, euros, minutos) e o que se guarda (pontos
 * base, centimos, micros). O SettingService por baixo nao conhece chave
 * nenhuma.
 *
 * Sao todos escalares. Ja aqui viveram duas tabelas de faixas — multiplicador
 * por custo e manuseamento por peso — e sairam com a formula que as usava.
 *
 * Os quatro `fallbackPrinter*()` no fim NAO sao definicoes: leem o config
 * direto, sem passar pela tabela. Quem manda no custo da maquina sao os perfis
 * de impressora, e esse caminho so se alcanca num estado que a interface ja
 * sinaliza como partido.
 */
class PricingSettings
{
    public const KEY_ELECTRICITY_PRICE_MICROS_PER_KWH = 'pricing.electricity_price_micros_per_kwh';

    public const KEY_LABOR_RATE_MICROS_PER_HOUR = 'pricing.labor_rate_micros_per_hour';

    public const KEY_ACTIVE_LABOR_MINUTES = 'pricing.active_labor_minutes';

    public const KEY_SETUP_LABOR_MINUTES = 'pricing.setup_labor_minutes';

    public const KEY_FAILURE_RATE_BP = 'pricing.failure_rate_bp';

    public const KEY_TARGET_WHOLESALE_MARGIN_BP = 'pricing.target_wholesale_margin_bp';

    public const KEY_TARGET_RESELLER_MARGIN_BP = 'pricing.target_reseller_margin_bp';

    public const KEY_MINIMUM_WHOLESALE_PRICE_CENTS = 'pricing.minimum_wholesale_price_cents';

    public const KEY_SALES_CHANNEL_FIXED_FEE_CENTS = 'pricing.sales_channel_fixed_fee_cents';

    public const KEY_SALES_CHANNEL_PERCENTAGE_FEE_BP = 'pricing.sales_channel_percentage_fee_bp';

    /** Todas as chaves que esta seccao possui — usado pelo "repor omissoes". */
    public const KEYS = [
        self::KEY_ELECTRICITY_PRICE_MICROS_PER_KWH,
        self::KEY_LABOR_RATE_MICROS_PER_HOUR,
        self::KEY_ACTIVE_LABOR_MINUTES,
        self::KEY_SETUP_LABOR_MINUTES,
        self::KEY_FAILURE_RATE_BP,
        self::KEY_TARGET_WHOLESALE_MARGIN_BP,
        self::KEY_TARGET_RESELLER_MARGIN_BP,
        self::KEY_MINIMUM_WHOLESALE_PRICE_CENTS,
        self::KEY_SALES_CHANNEL_FIXED_FEE_CENTS,
        self::KEY_SALES_CHANNEL_PERCENTAGE_FEE_BP,
    ];

    public function __construct(
        private SettingService $settings,
    ) {}

    /** A tarifa da luz. Global: o contrato e da casa, nao da impressora. */
    public function electricityPriceMicrosPerKwh(): int
    {
        return $this->int(self::KEY_ELECTRICITY_PRICE_MICROS_PER_KWH, 'electricity_price_micros_per_kwh');
    }

    /** Quanto vale a hora de quem trabalha na peca. */
    public function laborRateMicrosPerHour(): int
    {
        return $this->int(self::KEY_LABOR_RATE_MICROS_PER_HOUR, 'labor_rate_micros_per_hour');
    }

    /** Minutos de trabalho ativo por peca, quando a variante nao diz outra coisa. */
    public function activeLaborMinutes(): int
    {
        return $this->int(self::KEY_ACTIVE_LABOR_MINUTES, 'active_labor_minutes');
    }

    /** Minutos gastos UMA vez por mesa, em modo lote. */
    public function setupLaborMinutes(): int
    {
        return $this->int(self::KEY_SETUP_LABOR_MINUTES, 'setup_labor_minutes');
    }

    /** Taxa de falhas em pontos base. O custo divide-se por (1 - taxa). */
    public function failureRateBp(): int
    {
        return $this->int(self::KEY_FAILURE_RATE_BP, 'failure_rate_bp');
    }

    /** A margem que quero ter ao vender a um revendedor, sobre a venda. */
    public function targetWholesaleMarginBp(): int
    {
        return $this->int(self::KEY_TARGET_WHOLESALE_MARGIN_BP, 'target_wholesale_margin_bp');
    }

    /** A margem que quero deixar a quem me compra para revender. */
    public function targetResellerMarginBp(): int
    {
        return $this->int(self::KEY_TARGET_RESELLER_MARGIN_BP, 'target_reseller_margin_bp');
    }

    public function minimumWholesalePriceCents(): int
    {
        return $this->int(self::KEY_MINIMUM_WHOLESALE_PRICE_CENTS, 'minimum_wholesale_price_cents');
    }

    public function salesChannelFixedFeeCents(): int
    {
        return $this->int(self::KEY_SALES_CHANNEL_FIXED_FEE_CENTS, 'sales_channel_fixed_fee_cents');
    }

    public function salesChannelPercentageFeeBp(): int
    {
        return $this->int(self::KEY_SALES_CHANNEL_PERCENTAGE_FEE_BP, 'sales_channel_percentage_fee_bp');
    }

    /**
     * A maquina imaginaria, para quando nao ha nenhuma impressora ativa.
     *
     * Nao sao definicoes e nao aparecem no formulario: leem o config direto.
     * Ver o cabecalho da classe e o comentario no config/pricing.php.
     */
    public function fallbackPrinterPowerWatts(): int
    {
        return (int) config('pricing.fallback_printer_power_watts');
    }

    public function fallbackPrinterPurchasePriceCents(): int
    {
        return (int) config('pricing.fallback_printer_purchase_price_cents');
    }

    public function fallbackPrinterLifetimeHours(): int
    {
        return (int) config('pricing.fallback_printer_lifetime_hours');
    }

    public function fallbackPrinterMaintenanceMicrosPerHour(): int
    {
        return (int) config('pricing.fallback_printer_maintenance_micros_per_hour');
    }

    /**
     * O que o formulario das definicoes mostra: unidades humanas, strings
     * prontas a por num input. Percentagens em decimal, dinheiro em euros,
     * tempo em minutos inteiros.
     *
     * A eletricidade sai com QUATRO casas e a mao de obra com duas: sao a
     * mesma unidade guardada (micros) mas nao a mesma grandeza a ler. Uma
     * tarifa mostrada como "0.14" perdia os 0,0020 EUR/kWh que a distinguem da
     * seguinte; um custo de trabalho mostrado como "8.0000" so assusta.
     *
     * @return array{
     *     electricity_price: string,
     *     labor_rate: string,
     *     active_labor_minutes: int,
     *     setup_labor_minutes: int,
     *     failure_rate_percent: string,
     *     wholesale_margin_percent: string,
     *     reseller_margin_percent: string,
     *     minimum_wholesale_price: string,
     *     channel_fixed_fee: string,
     *     channel_percentage_fee: string,
     * }
     */
    public function toForm(): array
    {
        return [
            'electricity_price' => Micros::toDecimal($this->electricityPriceMicrosPerKwh()),
            'labor_rate' => Micros::toDecimal($this->laborRateMicrosPerHour(), 2),
            'active_labor_minutes' => $this->activeLaborMinutes(),
            'setup_labor_minutes' => $this->setupLaborMinutes(),
            'failure_rate_percent' => Rate::toPercent($this->failureRateBp()),
            'wholesale_margin_percent' => Rate::toPercent($this->targetWholesaleMarginBp()),
            'reseller_margin_percent' => Rate::toPercent($this->targetResellerMarginBp()),
            'minimum_wholesale_price' => Money::toDecimal($this->minimumWholesalePriceCents()),
            'channel_fixed_fee' => Money::toDecimal($this->salesChannelFixedFeeCents()),
            'channel_percentage_fee' => Rate::toPercent($this->salesChannelPercentageFeeBp()),
        ];
    }

    /**
     * O caminho inverso: o payload ja validado do UpdatePricingSettingsRequest
     * -> o mapa chave => valor pronto para o SettingService::setMany().
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public function fromForm(array $form): array
    {
        return [
            self::KEY_ELECTRICITY_PRICE_MICROS_PER_KWH => Micros::fromDecimal((string) $form['electricity_price']),
            self::KEY_LABOR_RATE_MICROS_PER_HOUR => Micros::fromDecimal((string) $form['labor_rate']),
            self::KEY_ACTIVE_LABOR_MINUTES => (int) $form['active_labor_minutes'],
            self::KEY_SETUP_LABOR_MINUTES => (int) $form['setup_labor_minutes'],
            self::KEY_FAILURE_RATE_BP => Rate::fromPercent((string) $form['failure_rate_percent']),
            self::KEY_TARGET_WHOLESALE_MARGIN_BP => Rate::fromPercent((string) $form['wholesale_margin_percent']),
            self::KEY_TARGET_RESELLER_MARGIN_BP => Rate::fromPercent((string) $form['reseller_margin_percent']),
            self::KEY_MINIMUM_WHOLESALE_PRICE_CENTS => Money::fromDecimal((string) $form['minimum_wholesale_price']),
            self::KEY_SALES_CHANNEL_FIXED_FEE_CENTS => Money::fromDecimal((string) $form['channel_fixed_fee']),
            self::KEY_SALES_CHANNEL_PERCENTAGE_FEE_BP => Rate::fromPercent((string) $form['channel_percentage_fee']),
        ];
    }

    /** Volta aos valores de config/pricing.php apagando as dez chaves. */
    public function resetToDefaults(): void
    {
        foreach (self::KEYS as $key) {
            $this->settings->forget($key);
        }
    }

    private function int(string $settingKey, string $configKey): int
    {
        $value = $this->settings->get($settingKey);

        // Uma definicao guardada com o tipo errado (JSON e JSON) nao pode
        // silenciosamente virar zero: uma failure_rate_bp a "" daria custo
        // sem risco nenhum e ninguem dava por isso.
        if (is_int($value)) {
            return $value;
        }

        return (int) config("pricing.{$configKey}");
    }
}
