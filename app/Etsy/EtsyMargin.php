<?php

namespace App\Etsy;

use App\Models\Variant;
use App\Services\PricingPreview;
use App\Support\Micros;

/**
 * O que sobra de um preco na Etsy: sem o IVA, sem as taxas dela e sem o custo
 * de producao.
 *
 * Calculadora propria, e nao a "taxa de canal" que a PricingCalculator ja
 * tem: essa e uma so para todos os canais e incide sobre o preco SUGERIDO
 * pela calculadora. Aqui interessa o preco que a variante tem mesmo, e as
 * taxas de um canal em particular.
 *
 * As taxas sao uma estimativa (EtsySettings) ate haver vendas; a partir dai
 * a fase das encomendas traz as taxas reais de cada uma.
 */
class EtsyMargin
{
    public function __construct(
        private PricingPreview $pricing,
        private EtsySettings $settings,
        private EtsyPricePolicy $policy,
    ) {}

    /**
     * @return array{price_cents: int, ex_vat_cents: int, fees_cents: int, net_cents: int, cost_cents: int|null, profit_cents: int|null, margin_bp: int|null}
     */
    public function forPrice(Variant $variant, int $priceCents, bool $withEtsyFees = true): array
    {
        $result = $this->pricing->forVariant($variant);

        // O preco traz IVA; o custo e o que sobra nao. O IVA e do produto.
        $vatBp = max(0, $variant->product->vat_rate) * 100;
        $exVat = (int) round($priceCents * 10_000 / (10_000 + $vatBp));

        // As taxas da Etsy incidem sobre o que o comprador paga, com IVA.
        $fees = $withEtsyFees && $priceCents > 0
            ? (int) round($priceCents * $this->settings->feePercentageBp() / 10_000) + $this->settings->feeFixedCents()
            : 0;

        $net = $exVat - $fees;

        // Sem peso, tempo e material nao ha custo — e nao se inventa um: um
        // lucro calculado contra custo zero enganava mais do que lucro nenhum.
        $cost = $result === null ? null : Micros::toCents($result->productionCostMicros);
        $profit = $cost === null ? null : $net - $cost;

        return [
            'price_cents' => $priceCents,
            'ex_vat_cents' => $exVat,
            'fees_cents' => $fees,
            'net_cents' => $net,
            'cost_cents' => $cost,
            'profit_cents' => $profit,
            'margin_bp' => $profit === null || $exVat <= 0 ? null : intdiv($profit * 10_000, $exVat),
        ];
    }

    /**
     * O site e a Etsy lado a lado, para a mesma variante: e esta comparacao
     * que deixa o dono decidir se a Etsy leva o mesmo preco, mais uma
     * percentagem, ou um preco proprio.
     *
     * @return array{sku: string, price_source: string, site: array<string, int|null>, etsy: array<string, int|null>}
     */
    public function compare(Variant $variant): array
    {
        return [
            'sku' => $variant->sku,
            'price_source' => $this->policy->source($variant),
            'site' => $this->forPrice($variant, $variant->price_cents, withEtsyFees: false),
            'etsy' => $this->forPrice($variant, $this->policy->priceCents($variant)),
        ];
    }
}
