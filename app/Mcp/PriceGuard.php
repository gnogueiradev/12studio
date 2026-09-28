<?php

namespace App\Mcp;

use App\Models\Variant;
use App\Services\PricingPreview;
use App\Support\Micros;

/**
 * Travao do lado do servidor para mudancas de preco pelo MCP.
 *
 * O modelo pode ter lido mal, ter-se enganado numa casa decimal, ou ter sido
 * manipulado por texto escondido numa encomenda. Nada disso depende de o
 * Claude "se portar bem": estas regras correm aqui, e a mudanca so passa com
 * `confirm: true` — que o Claude so deve mandar depois de mostrar a razao a
 * quem esta a usar.
 *
 * Pede confirmacao quando o preco que o cliente paga:
 *   - passa a zero;
 *   - muda mais de 50% (para cima ou para baixo);
 *   - fica abaixo do custo de producao calculado.
 */
final class PriceGuard
{
    /** Variacao maxima sem confirmacao, em pontos base (5000 = 50%). */
    public const MAX_CHANGE_BP = 5000;

    public function __construct(
        private PricingPreview $pricing,
    ) {}

    /**
     * Razoes para pedir confirmacao (vazio = pode seguir).
     *
     * @return array<int, string>
     */
    public function concerns(?Variant $current, int $newEffectiveCents, ?Variant $forCost = null): array
    {
        $concerns = [];

        if ($newEffectiveCents === 0) {
            $concerns[] = 'o preço passa a 0 €';
        }

        $oldCents = $current?->price_cents;

        if ($oldCents !== null && $oldCents > 0 && $newEffectiveCents > 0) {
            $changeBp = intdiv(abs($newEffectiveCents - $oldCents) * 10_000, $oldCents);

            if ($changeBp > self::MAX_CHANGE_BP) {
                $concerns[] = sprintf(
                    'o preço muda %d%% (de %s para %s)',
                    intdiv($changeBp, 100),
                    McpFormat::money($oldCents)['eur'],
                    McpFormat::money($newEffectiveCents)['eur'],
                );
            }
        }

        $costVariant = $forCost ?? $current;
        $result = $costVariant === null ? null : $this->pricing->forVariant($costVariant);

        if ($result !== null && $newEffectiveCents > 0) {
            $costCents = Micros::toCents($result->productionCostMicros);

            if ($newEffectiveCents < $costCents) {
                $concerns[] = sprintf(
                    'o preço (%s) fica abaixo do custo de produção (%s)',
                    McpFormat::money($newEffectiveCents)['eur'],
                    McpFormat::money($costCents)['eur'],
                );
            }
        }

        return $concerns;
    }

    /**
     * @param  array<int, string>  $concerns
     */
    public static function message(array $concerns): string
    {
        return 'Alteração de preço suspeita: '.implode('; ', $concerns)
            .'. Nada foi gravado. Mostra isto ao utilizador e, só se ele confirmar, repete com "confirm": true.';
    }
}
