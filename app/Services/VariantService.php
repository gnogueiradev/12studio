<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class VariantService
{
    public function __construct(
        private StockService $stockService,
    ) {}

    /**
     * O stock nunca entra pelo create em massa: entra sempre pelo
     * StockService, para que a primeira contagem fique registada como
     * movimento `initial` e o historico seja completo desde o dia um.
     *
     * @param  array<string, mixed>  $data
     */
    public function store(Product $product, array $data, ?User $by = null): Variant
    {
        return DB::transaction(function () use ($product, $data, $by): Variant {
            $stock = (int) ($data['stock'] ?? 0);
            $makeDefault = (bool) ($data['is_default'] ?? false);
            $data = $this->normalizePrices($data);

            $variant = $product->variants()->create([
                ...$data,
                'stock' => 0,
                // A primeira variante de um produto e sempre a default —
                // sem ela a montra nao sabe que preco mostrar.
                'is_default' => $makeDefault || ! $product->variants()->exists(),
            ]);

            if ($stock > 0) {
                $this->stockService->increment($variant, $stock, 'initial', null, $by, 'Stock inicial');
            }

            if ($variant->is_default) {
                $this->unsetOtherDefaults($variant);
            }

            return $variant;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Variant $variant, array $data, ?User $by = null): Variant
    {
        return DB::transaction(function () use ($variant, $data, $by): Variant {
            $stock = array_key_exists('stock', $data) ? (int) $data['stock'] : null;
            unset($data['stock']);

            $variant->update($this->normalizePrices($data));

            if ($stock !== null) {
                $this->stockService->setAbsolute($variant, $stock, 'manual_adjust', $by, 'Ajuste no formulario da variante');
            }

            if ($variant->is_default) {
                $this->unsetOtherDefaults($variant);
            }

            return $variant->refresh();
        });
    }

    /**
     * Marca uma variante como default e desmarca as irmas. Unica via de
     * escrita de `is_default` — duas defaults no mesmo produto tornariam
     * indeterminado o preco mostrado na montra.
     */
    public function setDefault(Variant $variant): void
    {
        DB::transaction(function () use ($variant): void {
            $variant->update(['is_default' => true]);
            $this->unsetOtherDefaults($variant);
        });
    }

    /**
     * Regra global de eliminacao: variantes nunca sao apagadas — tem
     * movimentos de stock e itens de encomenda agarrados.
     */
    public function archive(Variant $variant): void
    {
        $variant->update(['active' => false]);
    }

    /**
     * Euros do formulario -> centimos inteiros. O `price` cru nunca chega ao
     * model: nao existe como coluna.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePrices(array $data): array
    {
        if (array_key_exists('price', $data)) {
            $data['price_cents'] = Money::fromDecimal((string) $data['price']);
            unset($data['price']);
        }

        if (array_key_exists('compare_at_price', $data)) {
            $compareAt = $data['compare_at_price'];
            $data['compare_at_cents'] = ($compareAt === null || $compareAt === '')
                ? null
                : Money::fromDecimal((string) $compareAt);
            unset($data['compare_at_price']);
        }

        return $data;
    }

    private function unsetOtherDefaults(Variant $variant): void
    {
        Variant::query()
            ->where('product_id', $variant->product_id)
            ->whereKeyNot($variant->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
