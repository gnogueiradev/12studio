<?php

namespace App\Services;

use App\Support\Money;
use App\Support\Rate;

/**
 * Os parametros da calculadora de precos: config/pricing.php por omissao, com
 * a tabela `settings` por cima assim que o admin lhes mexer.
 *
 * E aqui — e so aqui — que se sabe que definicoes de preco existem: os nomes
 * das chaves, os tipos, os valores por omissao e a traducao entre o que o
 * formulario mostra (percentagem, euros, "1,75") e o que se guarda (pontos
 * base, centimos). O SettingService por baixo nao conhece chave nenhuma.
 *
 * Sao todos escalares. Ja aqui viveram duas tabelas de faixas — multiplicador
 * por custo e manuseamento por peso — e sairam com a formula que as usava; o
 * que resta e a lista de constantes que o dono consegue mesmo raciocinar.
 */
class PricingSettings
{
    public const KEY_FAILURE_RESERVE_BP = 'pricing.failure_reserve_bp';

    public const KEY_MINIMUM_RESALE_PRICE_CENTS = 'pricing.minimum_resale_price_cents';

    public const KEY_RESALE_MULTIPLIER_BP = 'pricing.resale_multiplier_bp';

    public const KEY_RETAIL_MULTIPLIER_BP = 'pricing.retail_multiplier_bp';

    public const KEY_MINIMUM_RETAIL_MULTIPLIER_BP = 'pricing.minimum_retail_multiplier_bp';

    public const KEY_HANDLING_COST_CENTS = 'pricing.handling_cost_cents';

    public const KEY_BATCH_JOB_HANDLING_CENTS = 'pricing.batch_job_handling_cents';

    public const KEY_BATCH_UNIT_HANDLING_CENTS = 'pricing.batch_unit_handling_cents';

    /** Todas as chaves que esta seccao possui — usado pelo "repor omissoes". */
    public const KEYS = [
        self::KEY_FAILURE_RESERVE_BP,
        self::KEY_MINIMUM_RESALE_PRICE_CENTS,
        self::KEY_RESALE_MULTIPLIER_BP,
        self::KEY_RETAIL_MULTIPLIER_BP,
        self::KEY_MINIMUM_RETAIL_MULTIPLIER_BP,
        self::KEY_HANDLING_COST_CENTS,
        self::KEY_BATCH_JOB_HANDLING_CENTS,
        self::KEY_BATCH_UNIT_HANDLING_CENTS,
    ];

    public function __construct(
        private SettingService $settings,
    ) {}

    public function failureReserveBp(): int
    {
        return $this->int(self::KEY_FAILURE_RESERVE_BP, 'failure_reserve_bp');
    }

    public function minimumResalePriceCents(): int
    {
        return $this->int(self::KEY_MINIMUM_RESALE_PRICE_CENTS, 'minimum_resale_price_cents');
    }

    /** Margem do produtor sobre o custo real. Unico, sem faixas. */
    public function resaleMultiplierBp(): int
    {
        return $this->int(self::KEY_RESALE_MULTIPLIER_BP, 'resale_multiplier_bp');
    }

    public function retailMultiplierBp(): int
    {
        return $this->int(self::KEY_RETAIL_MULTIPLIER_BP, 'retail_multiplier_bp');
    }

    /** Manuseamento por peca fora de lote. Fixo, seja qual for o peso. */
    public function handlingCostCents(): int
    {
        return $this->int(self::KEY_HANDLING_COST_CENTS, 'handling_cost_cents');
    }

    public function minimumRetailMultiplierBp(): int
    {
        return $this->int(self::KEY_MINIMUM_RETAIL_MULTIPLIER_BP, 'minimum_retail_multiplier_bp');
    }

    public function batchJobHandlingCents(): int
    {
        return $this->int(self::KEY_BATCH_JOB_HANDLING_CENTS, 'batch_job_handling_cents');
    }

    public function batchUnitHandlingCents(): int
    {
        return $this->int(self::KEY_BATCH_UNIT_HANDLING_CENTS, 'batch_unit_handling_cents');
    }

    /**
     * Custo/hora quando nao ha perfil de impressora. Nao e uma definicao: quem
     * manda no custo da maquina sao os perfis (/admin/impressoras), e isto e
     * so a rede de seguranca para quando nao existe nenhum ativo.
     */
    public function fallbackHourlyRateCents(): int
    {
        return (int) config('pricing.machine_hourly_rate_cents', 20);
    }

    /**
     * O que o formulario das definicoes mostra: unidades humanas, strings
     * prontas a por num input. Percentagem e multiplicadores em decimal,
     * dinheiro em euros.
     *
     * @return array{
     *     failure_reserve_percent: string,
     *     minimum_resale_price: string,
     *     resale_multiplier: string,
     *     retail_multiplier: string,
     *     minimum_retail_multiplier: string,
     *     handling_cost: string,
     *     batch_job_handling: string,
     *     batch_unit_handling: string,
     * }
     */
    public function toForm(): array
    {
        return [
            'failure_reserve_percent' => Rate::toPercent($this->failureReserveBp()),
            'minimum_resale_price' => Money::toDecimal($this->minimumResalePriceCents()),
            'resale_multiplier' => Rate::toMultiplier($this->resaleMultiplierBp()),
            'retail_multiplier' => Rate::toMultiplier($this->retailMultiplierBp()),
            'minimum_retail_multiplier' => Rate::toMultiplier($this->minimumRetailMultiplierBp()),
            'handling_cost' => Money::toDecimal($this->handlingCostCents()),
            'batch_job_handling' => Money::toDecimal($this->batchJobHandlingCents()),
            'batch_unit_handling' => Money::toDecimal($this->batchUnitHandlingCents()),
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
            self::KEY_FAILURE_RESERVE_BP => Rate::fromPercent((string) $form['failure_reserve_percent']),
            self::KEY_MINIMUM_RESALE_PRICE_CENTS => Money::fromDecimal((string) $form['minimum_resale_price']),
            self::KEY_RESALE_MULTIPLIER_BP => Rate::fromMultiplier((string) $form['resale_multiplier']),
            self::KEY_RETAIL_MULTIPLIER_BP => Rate::fromMultiplier((string) $form['retail_multiplier']),
            self::KEY_MINIMUM_RETAIL_MULTIPLIER_BP => Rate::fromMultiplier((string) $form['minimum_retail_multiplier']),
            self::KEY_HANDLING_COST_CENTS => Money::fromDecimal((string) $form['handling_cost']),
            self::KEY_BATCH_JOB_HANDLING_CENTS => Money::fromDecimal((string) $form['batch_job_handling']),
            self::KEY_BATCH_UNIT_HANDLING_CENTS => Money::fromDecimal((string) $form['batch_unit_handling']),
        ];
    }

    /** Volta aos valores de config/pricing.php apagando as oito chaves. */
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
        // silenciosamente virar zero: um failure_reserve_bp a "" daria custo
        // sem reserva nenhuma e ninguem dava por isso.
        if (is_int($value)) {
            return $value;
        }

        return (int) config("pricing.{$configKey}");
    }
}
