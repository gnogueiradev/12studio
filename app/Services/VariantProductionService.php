<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Variant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * A aba "Producao" do produto: o que cada variante custa a imprimir, e os
 * precos que saem dai.
 *
 * Duas accoes, deliberadamente separadas. Guardar tempos e gramagens nao mexe
 * em preco nenhum — o admin pode estar so a corrigir o que o slicer disse.
 * Aplicar precos nao le formulario nenhum — calcula a partir do que esta
 * GRAVADO, para o preco que fica na variante ser sempre o mesmo que a ficha
 * dela mostra como sugerido.
 */
class VariantProductionService
{
    public function __construct(
        private PricingPreview $pricing,
        private VariantService $variants,
    ) {}

    /**
     * @param  array<int, array{printing_time_minutes: int|null, filament_weight_grams: int|null}>  $rows  Indexado pelo id da variante.
     */
    public function updateProduction(Product $product, array $rows): void
    {
        DB::transaction(function () use ($product, $rows): void {
            $variants = $product->variants()->whereKey(array_keys($rows))->get();

            foreach ($variants as $variant) {
                $variant->update($rows[$variant->id]);
            }
        });
    }

    /**
     * Escreve em cada variante ativa o preco que a calculadora lhe da.
     *
     * Preco normal = preco ao cliente; preco de revenda = revenda. Uma
     * promocao que exista fica, desde que continue abaixo do novo preco normal
     * — acima dele deixava de ser desconto e cai, pela mesma regra que o
     * formulario da variante impoe a mao.
     *
     * As variantes sem conta possivel (sem peso, sem tempo, sem material) e as
     * arquivadas ficam como estao: o retorno diz quantas foram e quantas
     * ficaram, para o toast nao fingir que aplicou a todas.
     *
     * @return array{updated: int, skipped: int}
     */
    public function applyCalculatedPrices(Product $product): array
    {
        return DB::transaction(function () use ($product): array {
            $updated = 0;
            $skipped = 0;

            $variants = $product->variants()->where('active', true)->with('material')->get();

            foreach ($variants as $variant) {
                $result = $this->pricing->forVariant($variant);

                if ($result === null) {
                    $skipped++;

                    continue;
                }

                $this->applyTo($variant, $result->toArray());
                $updated++;
            }

            return ['updated' => $updated, 'skipped' => $skipped];
        });
    }

    /**
     * @param  array<string, mixed>  $result  O PricingResult::toArray().
     */
    private function applyTo(Variant $variant, array $result): void
    {
        $retailCents = (int) $result['retailPriceCents'];
        $wholesaleCents = (int) $result['wholesalePriceCents'];

        $sale = $variant->salePriceCents();
        $keepSale = $sale !== null && $sale < $retailCents;

        // Pelo VariantService e nao por um update() cru: e la que vive a
        // traducao normal/promocional -> price_cents/compare_at_cents, e so la.
        $this->variants->update($variant, [
            'normal_price' => Money::toDecimal($retailCents),
            'sale_price' => $keepSale ? Money::toDecimal((int) $sale) : null,
            'wholesale_price' => Money::toDecimal($wholesaleCents),
        ]);
    }
}
