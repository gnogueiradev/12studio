<?php

namespace App\Mcp\Tools\Write\Orders\Concerns;

use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

trait FindsOrders
{
    /**
     * @return array<string, mixed>
     */
    protected function orderLookupSchema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->integer()->description('Id da encomenda.'),
            'order_number' => $schema->string()->max(50)->description('Número da encomenda (em alternativa ao id).'),
        ];
    }

    protected function findOrder(Request $request): ?Order
    {
        $id = $request->get('order_id');
        $number = $request->get('order_number');

        if ($id === null && ($number === null || $number === '')) {
            return null;
        }

        return Order::query()
            ->with(['items', 'statusHistories', 'tags'])
            ->when(
                $id !== null,
                fn ($query) => $query->whereKey((int) $id),
                fn ($query) => $query->where('order_number', (string) $number),
            )
            ->first();
    }
}
