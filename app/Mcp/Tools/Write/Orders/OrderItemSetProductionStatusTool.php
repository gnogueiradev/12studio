<?php

namespace App\Mcp\Tools\Write\Orders;

use App\Http\Requests\Order\UpdateProductionStatusRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Presenters\OrderPresenter;
use App\Mcp\Tools\WriteTool;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use RuntimeException;

#[Name('order_item_set_production_status')]
#[Title('Mover artigo no quadro de produção')]
#[Description('Muda o estado de produção de um artigo de uma encomenda: awaiting_production → printing → quality_check → ready (e pode recuar, ex. uma peça que chumba no controlo volta a printing). Quando todos os artigos ficam prontos, a encomenda passa sozinha a ready_to_ship. O id do artigo está em order_get (items[].id).')]
#[IsDestructive(false)]
#[IsIdempotent]
class OrderItemSetProductionStatusTool extends WriteTool
{
    /** `not_required` nao e um degrau: e a marca de que a peca nao passa pelo quadro. */
    public const ALLOWED = ['awaiting_production', 'printing', 'quality_check', 'ready'];

    public function __construct(
        private FormRequestRunner $runner,
        private OrderService $orders,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->integer()->required()->description('Id do artigo (order_get → items[].id).'),
            'production_status' => $schema->string()->enum(self::ALLOWED)->required(),
            'note' => $schema->string()->max(300),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $item = OrderItem::query()->with('order')->find((int) $request->get('item_id'));

        if ($item === null) {
            return Response::error('Artigo não encontrado.');
        }

        $status = (string) $request->get('production_status');

        if (! in_array($status, self::ALLOWED, true)) {
            return Response::error('Estados possíveis: '.implode(', ', self::ALLOWED).'.');
        }

        $data = $this->runner->validate(UpdateProductionStatusRequest::class, [
            'production_status' => $status,
            'note' => $request->get('note'),
        ], $user, ['item' => $item]);

        $before = ['production_status' => $item->production_status, 'order_status' => $item->order->status];

        try {
            $item = $this->orders->setItemProductionStatus($item, $status, $user, $data['note'] ?? null);
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }

        $order = $item->order()->with(['items', 'statusHistories', 'tags'])->firstOrFail();

        $this->changes = [
            'item_id' => $item->id,
            'before' => $before,
            'after' => ['production_status' => $item->production_status, 'order_status' => $order->status],
        ];

        return Response::structured([
            'message' => "{$item->product_name}: {$before['production_status']} → {$item->production_status}. Encomenda em {$order->status}.",
            'order' => OrderPresenter::detail($order),
        ]);
    }
}
