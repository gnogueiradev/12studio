<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;

/**
 * Unica via de escrita de `variants.stock`.
 *
 * Todas as reducoes sao updates CONDICIONAIS: em SQLite o lockForUpdate() e
 * no-op, por isso a atomicidade tem de viver no WHERE. Ler o stock, decidir em
 * PHP e escrever depois abriria a porta a vender o mesmo artigo duas vezes.
 *
 * `reserved_stock` (janela Multibanco) so entra em jogo na Fase 3 — aqui
 * apenas se respeita, nunca se escreve.
 */
class StockService
{
    /**
     * Tenta retirar `$qty` do stock. Devolve false — sem escrever movimento —
     * quando nao ha disponivel suficiente. Quem chama decide o que fazer:
     * bloquear a criacao (encomenda manual) ou marcar `stock_issue` (webhook
     * de um pagamento ja feito, Fase 3).
     */
    public function decrement(
        Variant $variant,
        int $qty,
        string $reason,
        ?Order $order = null,
        ?User $by = null,
        ?string $note = null,
    ): bool {
        if ($qty < 1) {
            return false;
        }

        $affected = Variant::query()
            ->whereKey($variant->getKey())
            ->whereRaw('stock - reserved_stock >= ?', [$qty])
            ->decrement('stock', $qty);

        if ($affected === 0) {
            return false;
        }

        $this->recordMovement($variant, -$qty, $reason, $order, $by, $note);
        $variant->refresh();

        return true;
    }

    /**
     * Devolve stock (cancelamentos, reposicoes). Nunca falha: acrescentar
     * unidades nao tem invariante para violar.
     */
    public function increment(
        Variant $variant,
        int $qty,
        string $reason,
        ?Order $order = null,
        ?User $by = null,
        ?string $note = null,
    ): void {
        if ($qty < 1) {
            return;
        }

        Variant::query()
            ->whereKey($variant->getKey())
            ->increment('stock', $qty);

        $this->recordMovement($variant, $qty, $reason, $order, $by, $note);
        $variant->refresh();
    }

    /**
     * Ajuste manual do admin a partir de um valor absoluto (o formulario da
     * variante mostra o stock atual, nao um delta). Devolve false se o novo
     * valor deixasse menos unidades do que as ja reservadas.
     */
    public function setAbsolute(
        Variant $variant,
        int $newStock,
        string $reason = 'manual_adjust',
        ?User $by = null,
        ?string $note = null,
    ): bool {
        $delta = $newStock - $variant->stock;

        if ($delta === 0) {
            return true;
        }

        if ($delta < 0) {
            return $this->decrement($variant, -$delta, $reason, null, $by, $note);
        }

        $this->increment($variant, $delta, $reason, null, $by, $note);

        return true;
    }

    private function recordMovement(
        Variant $variant,
        int $delta,
        string $reason,
        ?Order $order,
        ?User $by,
        ?string $note,
    ): void {
        StockMovement::query()->create([
            'variant_id' => $variant->getKey(),
            'delta' => $delta,
            'reason' => $reason,
            'order_id' => $order?->getKey(),
            'created_by_user_id' => $by?->getKey(),
            'note' => $note,
        ]);
    }
}
