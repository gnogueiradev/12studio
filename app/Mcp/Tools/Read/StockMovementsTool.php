<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\McpFormat;
use App\Mcp\Tools\Concerns\Paginates;
use App\Mcp\Tools\StudioTool;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('stock_movements')]
#[Title('Movimentos de stock')]
#[Description('Histórico de entradas e saídas de stock (mais recentes primeiro): vendas, reposições por cancelamento, ajustes manuais, encomendas manuais e stock inicial. Filtra por variante ou por produto.')]
#[IsReadOnly]
#[IsIdempotent]
class StockMovementsTool extends StudioTool
{
    use Paginates;

    public function schema(JsonSchema $schema): array
    {
        return [
            'variant_id' => $schema->integer()->description('Só movimentos desta variante.'),
            'product_id' => $schema->integer()->description('Só movimentos das variantes deste produto.'),
            'reason' => $schema->string()->enum(StockMovement::REASONS)->description('Só movimentos com este motivo.'),
            ...$this->paginationSchema($schema),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $filters = $request->validate([
            'variant_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'in:'.implode(',', StockMovement::REASONS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::PER_PAGE_MAX],
        ]);

        $query = StockMovement::query()
            ->with(['variant.product', 'order', 'createdBy'])
            ->when(isset($filters['variant_id']), fn (Builder $query) => $query->where('variant_id', $filters['variant_id']))
            ->when(isset($filters['product_id']), fn (Builder $query) => $query->whereHas(
                'variant',
                fn (Builder $variant) => $variant->where('product_id', $filters['product_id']),
            ))
            ->when(isset($filters['reason']), fn (Builder $query) => $query->where('reason', $filters['reason']))
            ->latest('id');

        $page = $this->paginate($query, $request);

        return Response::structured([
            'movements' => collect($page->items())->map(fn (StockMovement $movement): array => [
                'id' => $movement->id,
                'at' => $movement->created_at?->format('Y-m-d H:i'),
                'variant_id' => $movement->variant_id,
                'product' => $movement->variant?->product?->name,
                'sku' => $movement->variant?->sku,
                'delta' => $movement->delta,
                'reason' => $movement->reason,
                'order_number' => $movement->order?->order_number,
                'by' => $movement->createdBy?->name,
                // A nota pode ter sido copiada de uma mensagem de cliente.
                'note' => McpFormat::untrusted($movement->note),
            ])->all(),
            'pagination' => $this->pageMeta($page),
        ]);
    }
}
