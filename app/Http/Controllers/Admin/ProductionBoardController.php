<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Support\OrderPresenter;
use Illuminate\Database\Eloquent\Collection;
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
            ->with([
                'order',
                // A variante so entra pelo tempo de impressao estimado.
                'variant',
                // Sem limit(): um cartao entra em `printing` uma mao-cheia de
                // vezes, e o limite por pai em eager loads e fragil.
                'statusHistories' => fn ($query) => $query->where('to_status', 'printing')->latest('id'),
            ])
            ->orderBy('created_at')
            ->get();

        // O contexto "1 de 2" e o estado dos irmaos saem do grupo, nao da
        // linha: um cartao sozinho nao sabe se a encomenda ja pode seguir.
        $byOrder = $items->groupBy('order_id');

        return Inertia::render('admin/producao/index', [
            'items' => $items
                ->map(fn (OrderItem $item): array => $this->card($item, $byOrder->get($item->order_id)))
                ->all(),
        ]);
    }

    /**
     * @param  Collection<int, OrderItem>|null  $siblings  Itens em producao da mesma encomenda, este incluido.
     * @return array<string, mixed>
     */
    private function card(OrderItem $item, ?Collection $siblings): array
    {
        $ordered = ($siblings ?? new Collection([$item]))->sortBy('id')->values();
        $position = $ordered->search(fn (OrderItem $sibling): bool => $sibling->is($item));

        $minutes = $item->variant?->printing_time_minutes;

        return [
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
            // Estimativa, nao medicao: a variante pode nem ter o tempo preenchido.
            'estimatedMinutes' => $minutes === null ? null : $minutes * $item->qty,
            'startedPrintingAt' => $item->statusHistories->first()?->created_at?->format('H:i'),
            'positionInOrder' => $position === false ? 1 : $position + 1,
            'totalInOrder' => $ordered->count(),
            'orderReadyToShip' => $ordered->every(
                fn (OrderItem $sibling): bool => $sibling->production_status === 'ready',
            ),
        ];
    }
}
