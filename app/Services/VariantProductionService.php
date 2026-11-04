<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Variant;
use App\Support\Money;
use App\Support\VariantSizes;
use Illuminate\Support\Facades\DB;

/**
 * A aba "Producao" do produto: o que a peca custa a imprimir em cada tamanho, e os precos
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
     * O tempo e a gramagem de cada tamanho em TODAS as variantes desse
     * tamanho, arquivadas incluidas: entre cores e materiais a peca e a
     * mesma, entre tamanhos nao. Um tamanho que nao venha fica como esta.
     *
     * Um UPDATE por tamanho, por ids: o tamanho e texto livre e a igualdade e
     * a do VariantSizes::key() (sem caixa, sem espacos nas pontas), que o
     * SQLite nao sabe fazer com acentos. O pedido ja garantiu que cada tamanho
     * e do produto e aparece uma vez.
     *
     * @param  list<array{size_label: string|null, values: array{printing_time_minutes: int|null, filament_weight_grams: int|null}}>  $sizes
     * @return int Quantas variantes ficaram com os valores.
     */
    public function updateProduction(Product $product, array $sizes): int
    {
        $variants = $product->variants()->get(['id', 'size_label']);

        return DB::transaction(function () use ($variants, $sizes): int {
            $count = 0;

            foreach ($sizes as $size) {
                $key = VariantSizes::key($size['size_label']);

                $ids = $variants
                    ->filter(fn (Variant $variant): bool => VariantSizes::key($variant->size_label) === $key)
                    ->modelKeys();

                if ($ids !== []) {
                    $count += Variant::query()->whereKey($ids)->update($size['values']);
                }
            }

            return $count;
        });
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
                // A taxa de IVA do preco e a do produto, que ja ca esta.
                $variant->setRelation('product', $product);

                if ($this->applyCalculatedPriceTo($variant)) {
                    $updated++;
                } else {
                    $skipped++;
                }
            }

            return ['updated' => $updated, 'skipped' => $skipped];
        });
    }

    /**
     * O que a aplicacao faria a UMA variante, sem gravar nada: os precos de
     * agora e os que ficariam. Null quando nao ha conta possivel. E o
     * "ensaio" que o MCP mostra antes de aplicar (dry run).
     *
     * @return array{normal_cents: int, sale_cents: int|null, wholesale_cents: int|null, suggested_normal_cents: int, kept_current: bool}|null
     */
    public function calculatedPricesFor(Variant $variant): ?array
    {
        $result = $this->pricing->forVariant($variant);

        return $result === null ? null : $this->guardedPrices($variant, $result->toArray());
    }

    /**
     * Os precos a gravar, com a regra da casa: NUNCA se baixa um preco ja
     * definido. A calculadora sugere o Recomendado; se o preco gravado ja esta
     * acima, fica o gravado. O mesmo para a revenda.
     *
     * Uma variante sem preco (zero) recebe o sugerido.
     *
     * @param  array<string, mixed>  $result  O PricingResult::toArray().
     * @return array{normal_cents: int, sale_cents: int|null, wholesale_cents: int|null, suggested_normal_cents: int, kept_current: bool}
     */
    private function guardedPrices(Variant $variant, array $result): array
    {
        $suggested = (int) $result['retailPriceCents'];
        $current = $variant->normalPriceCents();
        $normal = max($current, $suggested);

        $currentWholesale = $variant->wholesale_price_cents ?? 0;
        $wholesale = max($currentWholesale, (int) $result['wholesalePriceCents']);

        $sale = $variant->salePriceCents();

        return [
            'normal_cents' => $normal,
            'sale_cents' => $sale !== null && $sale < $normal ? $sale : null,
            'wholesale_cents' => $wholesale,
            'suggested_normal_cents' => $suggested,
            'kept_current' => $current > $suggested,
        ];
    }

    /**
     * Aplica a uma variante so. False quando nao ha conta possivel (fica
     * como esta).
     */
    public function applyCalculatedPriceTo(Variant $variant): bool
    {
        $result = $this->pricing->forVariant($variant);

        if ($result === null) {
            return false;
        }

        $this->applyTo($variant, $result->toArray());

        return true;
    }

    /**
     * @param  array<string, mixed>  $result  O PricingResult::toArray().
     */
    private function applyTo(Variant $variant, array $result): void
    {
        $prices = $this->guardedPrices($variant, $result);

        // Pelo VariantService e nao por um update() cru: e la que vive a
        // traducao normal/promocional -> price_cents/compare_at_cents, e so la.
        $this->variants->update($variant, [
            'normal_price' => Money::toDecimal($prices['normal_cents']),
            'sale_price' => $prices['sale_cents'] === null ? null : Money::toDecimal($prices['sale_cents']),
            'wholesale_price' => Money::toDecimal((int) $prices['wholesale_cents']),
        ]);
    }
}
