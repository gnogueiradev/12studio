<?php

namespace App\Mcp\Presenters;

use App\Mcp\McpFormat;
use App\Services\PricingSettings;
use App\Support\Micros;
use App\Support\PriceTier;
use App\Support\PricingResult;

/**
 * Um calculo de preco no formato do MCP. Um sitio so, para que o preco de uma
 * variante gravada (pricing_preview) e o de uma peca solta (pricing_calculate)
 * digam o mesmo da mesma maneira.
 *
 * Tudo por UNIDADE. Os precos vem com IVA incluido; os custos, os lucros e as
 * margens sao sem IVA — a mesma divisao do PricingResult.
 */
final class PricingPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function suggested(PricingResult $result): array
    {
        return [
            // Compatibilidade: retail_price e wholesale_price sao os de
            // sempre, agora com o valor do Recomendado (e da revenda que sai
            // dele). Os tres niveis estao em `tiers`.
            'retail_price' => McpFormat::micros($result->retailPriceMicros),
            'wholesale_price' => McpFormat::micros($result->wholesalePriceMicros),
            // Os mesmos dois sem IVA: e sobre estes, e nao sobre os de
            // cima, que as margens estao calculadas.
            'retail_price_ex_vat' => McpFormat::micros($result->retailPriceExVatMicros()),
            'wholesale_price_ex_vat' => McpFormat::micros($result->wholesalePriceExVatMicros()),
            'vat_percent' => $result->vatRateBp / 100,
            'wholesale_rule' => $result->tiers->wholesaleRule,
            'tiers' => self::tiers($result),
        ];
    }

    /**
     * Os tres niveis, por unidade, com os totais do trabalho ao lado.
     *
     * `rule` diz qual das regras decidiu o preco: margin (a margem de sempre),
     * per_hour (horas de impressora x lucro por hora) ou min_job (lucro minimo
     * do trabalho, so em orcamentos).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function tiers(PricingResult $result): array
    {
        $out = [];

        foreach ($result->tiers->all() as $name => $tier) {
            $out[$name] = [
                'price' => McpFormat::micros($tier->priceMicros),
                'price_ex_vat' => McpFormat::micros($tier->priceExVatMicros),
                'vat' => McpFormat::money(Micros::toCents($tier->priceMicros) - Micros::toCents($tier->priceExVatMicros)),
                'profit' => self::cents($tier->profitMicros()),
                'profit_total' => self::cents($tier->profitMicros() * $result->quantity),
                'margin_percent' => round($tier->marginBp() / 100, 1),
                'profit_per_print_hour' => self::cents($tier->profitPerPrintHourMicros()),
                // O lucro mais o meu trabalho e o design, por hora de impressora.
                'earnings_per_print_hour' => self::cents($tier->earningsPerPrintHourMicros()),
                'rule' => $tier->rule,
                'raised_for_order' => $tier->raisedForOrder,
                'design_cost' => self::cents($tier->designCostMicros),
            ];
        }

        return $out;
    }

    /**
     * A mao de obra discriminada, em minutos do trabalho, e o design.
     *
     * @return array<string, mixed>
     */
    public static function labor(PricingResult $result): array
    {
        return [
            'minutes' => [
                'base' => $result->laborBreakdownMinutes['base'],
                'quote_minimum' => $result->laborBreakdownMinutes['quote_minimum'],
                'plates' => $result->laborBreakdownMinutes['plates'],
                'color_changes' => $result->laborBreakdownMinutes['color_changes'],
                'total' => $result->laborMinutes,
            ],
            'design_minutes' => $result->designMinutes,
            'design_cost_per_unit' => self::cents($result->tiers->premium->designCostMicros),
        ];
    }

    /**
     * Os avisos do calculo. O do preco atual so existe para uma variante, e e
     * quem a tem que o passa.
     *
     * @return list<array{code: string, message: string}>
     */
    public static function warnings(PricingResult $result, ?int $currentPriceCents = null): array
    {
        $warnings = [];
        $minimum = $result->tiers->minimum;

        if ($currentPriceCents !== null) {
            $minimumCents = Micros::toCents($minimum->priceMicros);

            if ($currentPriceCents < $minimumCents) {
                $warnings[] = [
                    'code' => 'current_price_below_minimum',
                    'message' => 'O preço atual ('.self::eur($currentPriceCents).') está abaixo do Mínimo sugerido ('.self::eur($minimumCents).').',
                ];
            }

            $currentExVat = $result->exVat(Micros::fromCents($currentPriceCents));
            $hours = $minimum->printMilliMinutes;
            $currentPerHour = $hours <= 0 ? 0 : Micros::divRound(($currentExVat - $result->productionCostMicros) * 60_000, $hours);
            $floorPerHour = app(PricingSettings::class)->tierProfitMicrosPerHour(PriceTier::MINIMUM);

            if ($currentPerHour < $floorPerHour) {
                $warnings[] = [
                    'code' => 'profit_per_hour_below_minimum',
                    'message' => 'Ao preço atual, o lucro é '.self::eur(Micros::toCents($currentPerHour)).' por hora de impressora — abaixo dos '.self::eur(Micros::toCents($floorPerHour)).' do Mínimo.',
                ];
            }
        }

        if ($result->filamentReferenceExceeded()) {
            $warnings[] = [
                'code' => 'filament_reference_mismatch',
                'message' => 'O filamento calculado ('.self::eur(Micros::toCents($result->filamentJobCostAsWrittenMicros)).' com IVA) difere '
                    .str_replace('.', ',', (string) round((int) $result->filamentReferenceDiffBp() / 100, 1)).'% do valor do slicer ('.self::eur((int) $result->referenceFilamentCostCents).'). Confirma o €/kg do material e o peso.',
            ];
        }

        return $warnings;
    }

    private static function eur(int $cents): string
    {
        return McpFormat::money($cents)['eur'];
    }

    /**
     * @return array<string, array{eur: string, cents: int}>
     */
    public static function costs(PricingResult $result): array
    {
        return [
            'filament' => McpFormat::micros($result->filamentCostMicros),
            'electricity' => McpFormat::micros($result->electricityCostMicros),
            'depreciation' => McpFormat::micros($result->depreciationCostMicros),
            'maintenance' => McpFormat::micros($result->maintenanceCostMicros),
            'labor' => McpFormat::micros($result->laborCostMicros),
            'packaging' => McpFormat::micros($result->packagingCostMicros),
            'components' => McpFormat::micros($result->componentsCostMicros),
            'failure_reserve' => McpFormat::micros($result->failureCostMicros()),
            'production_total' => McpFormat::micros($result->productionCostMicros),
        ];
    }

    /**
     * @return array<string, float>
     */
    public static function margins(PricingResult $result): array
    {
        return [
            'direct_sale' => round($result->directMarginBp() / 100, 1),
            'wholesale' => round($result->wholesaleMarginBp() / 100, 1),
            'reseller' => round($result->resellerMarginBp() / 100, 1),
        ];
    }

    /**
     * O que sobra em cada venda, em euros. `direct_sale_after_channel_fees` e
     * a venda direta depois das comissoes do canal, que nao entram no custo.
     *
     * @return array<string, array{eur: string, cents: int}>
     */
    public static function profits(PricingResult $result): array
    {
        return [
            'direct_sale' => self::cents($result->directProfitMicros()),
            'channel_fees' => self::cents($result->channelFeeMicros),
            'direct_sale_after_channel_fees' => self::cents($result->netDirectProfitMicros()),
            'wholesale' => self::cents($result->wholesaleProfitMicros()),
            'reseller' => self::cents($result->resellerProfitMicros()),
        ];
    }

    /**
     * Os totais do trabalho todo: unidade x quantidade, nos dois modos.
     *
     * @return array<string, mixed>
     */
    public static function job(PricingResult $result): array
    {
        return [
            'quantity' => $result->quantity,
            'production_total' => self::cents($result->productionCostMicros * $result->quantity),
            'retail_total' => self::cents($result->retailPriceMicros * $result->quantity),
            'wholesale_total' => self::cents($result->wholesalePriceMicros * $result->quantity),
            'direct_sale_profit' => self::cents($result->directProfitMicros() * $result->quantity),
            'wholesale_profit' => self::cents($result->wholesaleProfitMicros() * $result->quantity),
        ];
    }

    /**
     * Pelo Micros::toCents() e nao pelo McpFormat::micros(): um lucro pode ser
     * negativo, e estes centimos tem de ser os que a pagina mostra.
     *
     * @return array{eur: string, cents: int}
     */
    private static function cents(int $micros): array
    {
        return McpFormat::money(Micros::toCents($micros));
    }
}
