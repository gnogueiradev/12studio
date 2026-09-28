<?php

namespace App\Mcp\Tools\Write;

use App\Mcp\Tools\WriteTool;
use App\Models\User;
use App\Models\Variant;
use App\Services\StockService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('stock_adjust')]
#[Title('Ajustar stock')]
#[Description('Dá entrada ou saída de stock de uma variante, com um movimento registado em nome da tua conta. Usa "delta" (ex. 5 = entraram 5, -2 = saíram 2) ou "set_to" (o stock físico passa a ser este número). No máximo 1000 unidades de diferença por chamada, e nunca abaixo das unidades já reservadas. Diz sempre o porquê em "note".')]
#[IsDestructive(false)]
class StockAdjustTool extends WriteTool
{
    /** Diferenca maxima por chamada: acima disto e engano, nao uma contagem. */
    public const MAX_DELTA = 1000;

    public function __construct(
        private StockService $stock,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'variant_id' => $schema->integer()->required()->description('Id da variante.'),
            'delta' => $schema->integer()->min(-self::MAX_DELTA)->max(self::MAX_DELTA)->description('Unidades a somar (positivo) ou a tirar (negativo).'),
            'set_to' => $schema->integer()->min(0)->max(99999)->description('Novo stock físico (em alternativa a delta).'),
            'note' => $schema->string()->max(200)->required()->description('Porquê (ex. "contagem de sábado", "impressas 5 novas").'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer'],
            'delta' => ['nullable', 'integer', 'min:-'.self::MAX_DELTA, 'max:'.self::MAX_DELTA, 'not_in:0', 'required_without:set_to', 'prohibits:set_to'],
            'set_to' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'note' => ['required', 'string', 'max:200'],
        ], [
            'delta.required_without' => 'Indica "delta" ou "set_to".',
            'delta.prohibits' => 'Usa "delta" ou "set_to", não os dois.',
            'delta.not_in' => 'Um delta de 0 não muda nada.',
        ]);

        $variant = Variant::query()->with('product')->find((int) $data['variant_id']);

        if ($variant === null) {
            return Response::error('Variante não encontrada.');
        }

        $before = $variant->stock;
        $delta = isset($data['set_to']) ? (int) $data['set_to'] - $before : (int) $data['delta'];

        if ($delta === 0) {
            return Response::structured([
                'message' => "O stock já é {$before}: nada mudou.",
                'stock' => $before,
            ]);
        }

        if (abs($delta) > self::MAX_DELTA) {
            return Response::error("Isso muda o stock em {$delta} unidades — mais do que ".self::MAX_DELTA.' de uma vez. Se é mesmo isso, faz o ajuste no backoffice.');
        }

        $note = 'MCP: '.trim((string) $data['note']);

        if ($delta > 0) {
            $this->stock->increment($variant, $delta, 'manual_adjust', null, $user, $note);
        } elseif (! $this->stock->decrement($variant, -$delta, 'manual_adjust', null, $user, $note)) {
            return Response::error(sprintf(
                'Não há stock disponível suficiente: físico %d, reservado %d, disponível %d. Nada foi alterado.',
                $variant->stock,
                $variant->reserved_stock,
                $variant->available_stock,
            ));
        }

        $variant->refresh();

        $this->changes = ['variant_id' => $variant->id, 'before' => ['stock' => $before], 'after' => ['stock' => $variant->stock]];

        return Response::structured([
            'message' => sprintf('Stock de %s: %d → %d.', $variant->sku, $before, $variant->stock),
            'variant_id' => $variant->id,
            'stock' => $variant->stock,
            'reserved_stock' => $variant->reserved_stock,
            'available_stock' => $variant->available_stock,
        ]);
    }
}
