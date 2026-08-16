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

            /*
             * Mexer no `active` a mao passa a decisao para o dono. A flag existe
             * so para o ColorService saber o que PODE ressuscitar quando um
             * material volta a cor; a partir do momento em que alguem escolheu
             * este interruptor, o catalogo deixa de mandar nele.
             */
            if (array_key_exists('active', $data)) {
                $data['hidden_by_palette'] = false;
            }

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
        // Escondida a mao, e nao pelo catalogo: nao volta sozinha quando a cor
        // recuperar o material (ver ColorService::syncMaterials).
        $variant->update(['active' => false, 'hidden_by_palette' => false]);
    }

    /**
     * Euros do formulario -> centimos inteiros. Os campos crus nunca chegam
     * ao model: nao existem como colunas.
     *
     * O formulario fala a lingua do admin (preco normal + preco promocional);
     * a BD guarda `price_cents` = preco EFETIVO e `compare_at_cents` = preco
     * riscado. Com promocao os dois trocam de lugar — e esta a unica funcao
     * onde essa traducao acontece na escrita (na leitura sao os acessores
     * Variant::normal_price_cents / sale_price_cents).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePrices(array $data): array
    {
        if (array_key_exists('normal_price', $data)) {
            $normal = Money::fromDecimal((string) $data['normal_price']);
            $sale = $this->optionalCents($data['sale_price'] ?? null);

            $data['price_cents'] = $sale ?? $normal;
            $data['compare_at_cents'] = $sale === null ? null : $normal;
        }

        if (array_key_exists('wholesale_price', $data)) {
            $data['wholesale_price_cents'] = $this->optionalCents($data['wholesale_price']);
        }

        // Imanes, feltro, argolas, caixa: tudo o que se soma ao custo desta
        // peca depois de ela sair da impressora. Entra na calculadora a cru,
        // sem reserva de falha por cima.
        if (array_key_exists('extra_cost', $data)) {
            $data['extra_cost_cents'] = $this->optionalCents($data['extra_cost']);
        }

        unset($data['normal_price'], $data['sale_price'], $data['wholesale_price'], $data['extra_cost']);

        return $data;
    }

    /**
     * Campo de preco opcional: vazio e null significam "nao tem", nao "zero".
     */
    private function optionalCents(mixed $value): ?int
    {
        return ($value === null || $value === '')
            ? null
            : Money::fromDecimal((string) $value);
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
