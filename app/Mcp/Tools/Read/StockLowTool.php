<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\Tools\Concerns\Paginates;
use App\Mcp\Tools\StudioTool;
use App\Models\User;
use App\Models\Variant;
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

#[Name('stock_low')]
#[Title('Stock baixo')]
#[Description('Variantes ativas (de produtos não arquivados) cujo stock disponível — físico menos reservado — está no limite de alerta ou abaixo. Ordenadas das mais em falta para as menos.')]
#[IsReadOnly]
#[IsIdempotent]
class StockLowTool extends StudioTool
{
    use Paginates;

    public function schema(JsonSchema $schema): array
    {
        return $this->paginationSchema($schema);
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::PER_PAGE_MAX],
        ]);

        $query = Variant::query()
            ->with(['product', 'color', 'material'])
            ->where('active', true)
            ->whereHas('product', fn (Builder $product) => $product->where('status', '!=', 'archived'))
            ->whereRaw('(stock - reserved_stock) <= low_stock_threshold')
            ->orderByRaw('(stock - reserved_stock) - low_stock_threshold')
            ->orderBy('id');

        $page = $this->paginate($query, $request);

        return Response::structured([
            'variants' => collect($page->items())->map(fn (Variant $variant): array => [
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'product' => $variant->product->name,
                'sku' => $variant->sku,
                'color' => $variant->color?->name,
                'material' => $variant->material?->name,
                'size' => $variant->size_label,
                'stock' => $variant->stock,
                'reserved_stock' => $variant->reserved_stock,
                'available_stock' => $variant->available_stock,
                'low_stock_threshold' => $variant->low_stock_threshold,
                'fulfillment_mode' => $variant->product->fulfillment_mode,
            ])->all(),
            'pagination' => $this->pageMeta($page),
        ]);
    }
}
