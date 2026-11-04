<?php

namespace App\Services;

use App\Support\Micros;
use App\Support\PricingResult;
use Illuminate\Support\Facades\Log;

/**
 * O registo dos calculos de preco, no canal por omissao (o mesmo de todos os
 * outros avisos do backoffice).
 *
 * So numeros da PECA — material, gramas, minutos, precos. Nunca nada de
 * clientes: um orcamento pode ser para alguem, mas o que se regista e a peca.
 *
 * Nao se regista no PricingPreview::forVariant(): esse corre por baixo da Etsy
 * e do travao de precos do MCP, e enchia o log com calculos que ninguem pediu.
 */
class PricingLog
{
    public static function calculated(string $source, ?int $materialId, int $weightGrams, PricingResult $result): void
    {
        $tiers = [];

        foreach ($result->tiers->all() as $name => $tier) {
            $tiers[$name] = [
                'price_cents' => Micros::toCents($tier->priceMicros),
                'rule' => $tier->rule,
            ];
        }

        Log::info('Preços: cálculo.', [
            'source' => $source,
            'material_id' => $materialId,
            'weight_grams' => $weightGrams,
            'print_minutes' => $result->printMinutes,
            'plates' => $result->plates,
            'manual_color_changes' => $result->manualColorChanges,
            'labor_minutes' => $result->laborBreakdownMinutes,
            'design_minutes' => $result->designMinutes,
            'quantity' => $result->quantity,
            'mode' => $result->mode,
            'quote' => $result->quote,
            'production_cost_cents' => Micros::toCents($result->productionCostMicros),
            'tiers' => $tiers,
            'wholesale_cents' => Micros::toCents($result->wholesalePriceMicros),
        ]);
    }

    /** O preco gravado de uma variante esta abaixo do Minimo sugerido. */
    public static function belowMinimum(int $variantId, int $currentCents, int $minimumCents): void
    {
        Log::warning('Preços: variante abaixo do Mínimo.', [
            'variant_id' => $variantId,
            'current_cents' => $currentCents,
            'minimum_cents' => $minimumCents,
        ]);
    }
}
