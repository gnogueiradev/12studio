<?php

namespace App\Services;

use App\Support\Money;
use App\Support\Rate;
use LogicException;

/**
 * Os parametros da calculadora de precos: config/pricing.php por omissao, com
 * a tabela `settings` por cima assim que o admin lhes mexer.
 *
 * E aqui — e so aqui — que se sabe que definicoes de preco existem: os nomes
 * das chaves, os tipos, os valores por omissao e a traducao entre o que o
 * formulario mostra (percentagem, euros, "1,75") e o que se guarda (pontos
 * base, centimos). O SettingService por baixo nao conhece chave nenhuma.
 *
 * As duas tabelas (multiplicadores e faixas de manuseamento) guardam-se
 * INTEIRAS numa chave cada: editam-se como uma unidade, e fundir metade de uma
 * tabela nova com metade da antiga dava precos que nao correspondem a
 * configuracao nenhuma.
 */
class PricingSettings
{
    public const KEY_FAILURE_RESERVE_BP = 'pricing.failure_reserve_bp';

    public const KEY_MINIMUM_RESALE_PRICE_CENTS = 'pricing.minimum_resale_price_cents';

    public const KEY_RETAIL_MULTIPLIER_BP = 'pricing.retail_multiplier_bp';

    public const KEY_MINIMUM_RETAIL_MULTIPLIER_BP = 'pricing.minimum_retail_multiplier_bp';

    public const KEY_RESALE_MULTIPLIERS = 'pricing.resale_multipliers';

    public const KEY_HANDLING_TIERS = 'pricing.handling_tiers';

    public const KEY_BATCH_JOB_HANDLING_CENTS = 'pricing.batch_job_handling_cents';

    public const KEY_BATCH_UNIT_HANDLING_CENTS = 'pricing.batch_unit_handling_cents';

    /** Todas as chaves que esta seccao possui — usado pelo "repor omissoes". */
    public const KEYS = [
        self::KEY_FAILURE_RESERVE_BP,
        self::KEY_MINIMUM_RESALE_PRICE_CENTS,
        self::KEY_RETAIL_MULTIPLIER_BP,
        self::KEY_MINIMUM_RETAIL_MULTIPLIER_BP,
        self::KEY_RESALE_MULTIPLIERS,
        self::KEY_HANDLING_TIERS,
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

    public function retailMultiplierBp(): int
    {
        return $this->int(self::KEY_RETAIL_MULTIPLIER_BP, 'retail_multiplier_bp');
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
        return (int) config('pricing.machine_hourly_rate_cents', 50);
    }

    /**
     * Multiplicador de revenda por faixa de custo de producao (limiar em
     * centimos, valor em pontos base). O ultimo limiar e null — faixa aberta.
     *
     * @return list<array{threshold: int|null, value: int}>
     */
    public function resaleMultipliers(): array
    {
        return $this->tiers(self::KEY_RESALE_MULTIPLIERS, 'resale_multipliers', 'up_to_cents', 'bp');
    }

    /**
     * Custo de manuseamento por faixa de peso (limiar em gramas, valor em
     * centimos). O ultimo limiar e null — faixa aberta.
     *
     * @return list<array{threshold: int|null, value: int}>
     */
    public function handlingTiers(): array
    {
        return $this->tiers(self::KEY_HANDLING_TIERS, 'handling_tiers', 'up_to_grams', 'cents');
    }

    /**
     * O que o formulario das definicoes mostra: unidades humanas, strings
     * prontas a por num input. Percentagem e multiplicadores em decimal,
     * dinheiro em euros.
     *
     * @return array{
     *     failure_reserve_percent: string,
     *     minimum_resale_price: string,
     *     retail_multiplier: string,
     *     minimum_retail_multiplier: string,
     *     batch_job_handling: string,
     *     batch_unit_handling: string,
     *     resale_multipliers: list<array{up_to: string|null, value: string}>,
     *     handling_tiers: list<array{up_to_grams: int|null, value: string}>,
     * }
     */
    public function toForm(): array
    {
        return [
            'failure_reserve_percent' => Rate::toPercent($this->failureReserveBp()),
            'minimum_resale_price' => Money::toDecimal($this->minimumResalePriceCents()),
            'retail_multiplier' => Rate::toMultiplier($this->retailMultiplierBp()),
            'minimum_retail_multiplier' => Rate::toMultiplier($this->minimumRetailMultiplierBp()),
            'batch_job_handling' => Money::toDecimal($this->batchJobHandlingCents()),
            'batch_unit_handling' => Money::toDecimal($this->batchUnitHandlingCents()),
            'resale_multipliers' => array_map(
                fn (array $tier): array => [
                    'up_to' => $tier['threshold'] === null ? null : Money::toDecimal($tier['threshold']),
                    'value' => Rate::toMultiplier($tier['value']),
                ],
                $this->resaleMultipliers(),
            ),
            'handling_tiers' => array_map(
                fn (array $tier): array => [
                    'up_to_grams' => $tier['threshold'],
                    'value' => Money::toDecimal($tier['value']),
                ],
                $this->handlingTiers(),
            ),
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
            self::KEY_RETAIL_MULTIPLIER_BP => Rate::fromMultiplier((string) $form['retail_multiplier']),
            self::KEY_MINIMUM_RETAIL_MULTIPLIER_BP => Rate::fromMultiplier((string) $form['minimum_retail_multiplier']),
            self::KEY_BATCH_JOB_HANDLING_CENTS => Money::fromDecimal((string) $form['batch_job_handling']),
            self::KEY_BATCH_UNIT_HANDLING_CENTS => Money::fromDecimal((string) $form['batch_unit_handling']),
            self::KEY_RESALE_MULTIPLIERS => array_values(array_map(
                fn (array $tier): array => [
                    'up_to_cents' => ($tier['up_to'] ?? null) === null
                        ? null
                        : Money::fromDecimal((string) $tier['up_to']),
                    'bp' => Rate::fromMultiplier((string) $tier['value']),
                ],
                (array) $form['resale_multipliers'],
            )),
            self::KEY_HANDLING_TIERS => array_values(array_map(
                fn (array $tier): array => [
                    'up_to_grams' => ($tier['up_to_grams'] ?? null) === null
                        ? null
                        : (int) $tier['up_to_grams'],
                    'cents' => Money::fromDecimal((string) $tier['value']),
                ],
                (array) $form['handling_tiers'],
            )),
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

    /**
     * @return list<array{threshold: int|null, value: int}>
     */
    private function tiers(string $settingKey, string $configKey, string $thresholdKey, string $valueKey): array
    {
        $stored = $this->normalizeTiers($this->settings->get($settingKey), $thresholdKey, $valueKey);

        if ($stored !== null) {
            return $stored;
        }

        $default = $this->normalizeTiers(config("pricing.{$configKey}"), $thresholdKey, $valueKey);

        if ($default === null) {
            // Nao e um dado do admin — e o ficheiro de config do deploy. Falhar
            // alto, porque em silencio isto virava manuseamento a zero.
            throw new LogicException("config/pricing.php: {$configKey} tem uma forma invalida.");
        }

        return $default;
    }

    /**
     * Valida a forma e normaliza para (limiar, valor) — os nomes descritivos
     * ficam no config e no formulario, o calculador so quer os pares.
     *
     * Devolve null se a tabela estiver malformada, para quem chama cair para o
     * config em vez de calcular com lixo.
     *
     * @return list<array{threshold: int|null, value: int}>|null
     */
    private function normalizeTiers(mixed $raw, string $thresholdKey, string $valueKey): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $tiers = [];

        foreach ($raw as $row) {
            if (! is_array($row) || ! array_key_exists($thresholdKey, $row) || ! isset($row[$valueKey])) {
                return null;
            }

            $threshold = $row[$thresholdKey];

            if ($threshold !== null && ! is_int($threshold)) {
                return null;
            }

            if (! is_int($row[$valueKey])) {
                return null;
            }

            $tiers[] = ['threshold' => $threshold, 'value' => $row[$valueKey]];
        }

        // A ultima faixa TEM de ser aberta, senao um peso acima do maior limiar
        // nao tinha custo de manuseamento nenhum.
        if ($tiers[count($tiers) - 1]['threshold'] !== null) {
            return null;
        }

        return $tiers;
    }
}
