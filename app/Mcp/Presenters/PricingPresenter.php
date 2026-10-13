<?php

namespace App\Mcp\Presenters;

use App\Mcp\McpFormat;
use App\Support\Micros;
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
            'retail_price' => McpFormat::micros($result->retailPriceMicros),
            'wholesale_price' => McpFormat::micros($result->wholesalePriceMicros),
            // Os mesmos dois sem IVA: e sobre estes, e nao sobre os de
            // cima, que as margens estao calculadas.
            'retail_price_ex_vat' => McpFormat::micros($result->retailPriceExVatMicros()),
            'wholesale_price_ex_vat' => McpFormat::micros($result->wholesalePriceExVatMicros()),
            'vat_percent' => $result->vatRateBp / 100,
        ];
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
