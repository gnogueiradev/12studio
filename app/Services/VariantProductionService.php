<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Variant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * A aba "Producao" do produto: o que a peca custa a imprimir, e os precos
 * que saem dai para cada variante.
 *
 * Duas accoes, deliberadamente separadas. Guardar o tempo e a gramagem nao
 * mexe em preco nenhum — o admin pode estar so a corrigir o que o slicer
 * disse. Aplicar precos nao le formulario nenhum — calcula a partir do que
 * esta GRAVADO, para o preco que fica na variante ser sempre o mesmo que a
 * aba mostra como sugerido.
 */
class VariantProductionService
{
    public function __construct(
        private PricingPreview $pricing,
        private VariantService $variants,
    ) {}

    /**
     * O mesmo tempo e a mesma gramagem em TODAS as variantes do produto,
     * arquivadas incluidas: a peca e a mesma, muda a cor e o material.
     *
     * Um so UPDATE em vez de um por variante — nao ha traducao nenhuma a
     * fazer nestas duas colunas, e o Builder do Eloquent ainda carimba o
     * updated_at.
     *
     * @param  array{printing_time_minutes: int|null, filament_weight_grams: int|null}  $values
     * @return int Quantas variantes ficaram com os valores.
     */
    public function updateProduction(Product $product, array $values): int
    {
        return $product->variants()->update($values);
    }

    /**
     * Escreve em cada variante o preco que a calculadora lhe da: preco normal
     * = preco ao cliente, preco de revenda = revenda.
     *
     * Todas, arquivadas incluidas — uma variante que volte a montra com o
     * preco antigo era uma armadilha, e escrever-lhe o preco novo nao custa
     * nada enquanto esta fora dela.
     *
     * Uma promocao que exista fica, desde que continue abaixo do novo preco
     * normal — acima dele deixava de ser desconto e cai, pela mesma regra que
     * o formulario da variante impoe a mao.
     *
     * As variantes sem conta possivel (sem peso, sem tempo, sem material)
     * ficam como estao: o retorno diz quantas foram e quantas ficaram, para o
     * toast nao fingir que aplicou a todas.
     *
     * @return array{updated: int, skipped: int}
     */
    public function applyCalculatedPrices(Product $product): array
    {
        return DB::transaction(function () use ($product): array {
            $updated = 0;
            $skipped = 0;

            $variants = $product->variants()->with('material')->get();

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
