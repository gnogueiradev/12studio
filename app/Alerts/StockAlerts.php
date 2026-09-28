<?php

namespace App\Alerts;

use App\Models\Material;
use App\Models\User;
use App\Models\Variant;
use Throwable;

/**
 * Stock em #stock-producao: ajustes a mao, variantes que cruzam o limiar ou
 * ficam sem stock, e bobines de filamento abaixo do minimo.
 */
class StockAlerts
{
    /** Movimentos feitos a mao: pelo formulario da variante ou pelo Claude. */
    private const MANUAL_REASONS = ['manual_adjust'];

    public function __construct(
        private AlertSender $sender,
    ) {}

    /**
     * Chamado pelo StockService depois de cada movimento. Avisa do ajuste
     * manual e de cada limiar CRUZADO — nao de cada venda abaixo dele: uma
     * variante com o limiar a 5 que vende de 4 para 3 ja foi avisada.
     */
    public function moved(Variant $variant, int $before, int $after, string $reason, ?User $by, ?string $note): void
    {
        try {
            $variant->loadMissing('product');

            if (in_array($reason, self::MANUAL_REASONS, true)) {
                $this->manualAdjust($variant, $before, $after, $by, $note);
            }

            // Arquivada, inativa ou de produto que nao se vende: sem alarme.
            if (! $variant->active || ! $variant->product->isActive()) {
                return;
            }

            $availableBefore = $before - $variant->reserved_stock;
            $availableAfter = $after - $variant->reserved_stock;

            if ($availableAfter <= 0 && $availableBefore > 0) {
                $this->send(DiscordMessage::make("🚫 Sem stock — {$this->name($variant)}", DiscordMessage::DANGER)
                    ->field('SKU', $variant->sku)
                    ->field('Disponível', (string) $availableAfter), $variant);
            } elseif ($availableAfter <= $variant->low_stock_threshold && $availableBefore > $variant->low_stock_threshold) {
                $this->send(DiscordMessage::make("📉 Stock baixo — {$this->name($variant)}", DiscordMessage::WARNING)
                    ->field('SKU', $variant->sku)
                    ->field('Disponível', (string) $availableAfter)
                    ->field('Limiar', (string) $variant->low_stock_threshold), $variant);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function materialLow(Material $material): void
    {
        try {
            $message = DiscordMessage::make("🧵 Bobines a acabar — {$material->name}", DiscordMessage::WARNING)
                ->field('Em stock', (string) $material->spools_in_stock)
                ->field('Mínimo', (string) $material->min_spools)
                ->field('Fornecedor', $material->supplier)
                ->url(route('admin.materiais.index'));

            $this->sender->send(AlertChannel::STOCK, $message);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function manualAdjust(Variant $variant, int $before, int $after, ?User $by, ?string $note): void
    {
        $delta = $after - $before;

        $this->send(DiscordMessage::make("🔧 Stock ajustado — {$this->name($variant)}", DiscordMessage::INFO)
            ->field('SKU', $variant->sku)
            ->field('Antes → agora', "{$before} → {$after} (".($delta > 0 ? '+' : '')."{$delta})")
            ->field('Por', $this->sender->actor($by))
            ->field('Nota', $note, inline: false), $variant);
    }

    private function name(Variant $variant): string
    {
        $product = $variant->product->name ?? $variant->sku;

        return $variant->size_label ? "{$product} ({$variant->size_label})" : $product;
    }

    private function send(DiscordMessage $message, Variant $variant): void
    {
        $this->sender->send(AlertChannel::STOCK, $message->url(route('admin.produtos.edit', $variant->product_id)));
    }
}
