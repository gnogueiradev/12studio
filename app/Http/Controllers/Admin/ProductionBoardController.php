<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Support\OrderPresenter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quadro de producao ao nivel do ITEM — nao da encomenda. Uma encomenda
 * mista (uma peca em stock + uma por imprimir) so mostra aqui a segunda,
 * e so avanca para expedicao quando essa ficar pronta.
 */
class ProductionBoardController extends Controller
{
    public function __invoke(): Response
    {
        $items = OrderItem::query()
            ->whereIn('production_status', ['awaiting_production', 'printing', 'quality_check', 'ready'])
            // Encomendas mortas nao ocupam o quadro.
            ->whereHas('order', fn ($query) => $query->whereNotIn('status', ['cancelled', 'refunded']))
            ->with('order')
            ->orderBy('created_at')
            ->get()
            ->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'orderId' => $item->order->id,
                'orderNumber' => $item->order->order_number,
                'customerName' => $item->order->customer_name,
                'productName' => $item->product_name,
                'variantLabel' => $item->variant_label,
                'qty' => $item->qty,
                'productionStatus' => $item->production_status,
                'personalization' => OrderPresenter::personalization($item),
                'orderedAt' => $item->order->created_at?->format('Y-m-d'),
            ])
            ->all();

        return Inertia::render('admin/producao/index', [
            'items' => $items,
        ]);
    }
}
