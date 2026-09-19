<?php

namespace App\Mcp\Tools\Write\Orders;

use App\Http\Requests\Order\UpdateOrderPaymentRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Presenters\OrderPresenter;
use App\Mcp\Tools\Write\Orders\Concerns\FindsOrders;
use App\Mcp\Tools\WriteTool;
use App\Models\Order;
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
use RuntimeException;

#[Name('order_set_payment_status')]
#[Title('Marcar pagamento')]
#[Description('Marca o pagamento de uma encomenda como recebido (paid) — o que a faz avançar sozinha para produção ou expedição — ou volta a pendente. Podes indicar também o método de pagamento. Falhas e reembolsos fazem-se no backoffice.')]
#[IsDestructive(false)]
class OrderSetPaymentStatusTool extends WriteTool
{
    use FindsOrders;

    /** "failed" cancela a encomenda e "refunded" reembolsa: ficam no backoffice. */
    public const ALLOWED = ['paid', 'pending'];

    public function __construct(
        private FormRequestRunner $runner,
        private OrderService $orders,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->orderLookupSchema($schema),
            'payment_status' => $schema->string()->enum(self::ALLOWED)->required(),
            'payment_method' => $schema->string()->enum(Order::PAYMENT_METHODS),
            'note' => $schema->string()->max(300),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $order = $this->findOrder($request);

        if ($order === null) {
            return Response::error('Encomenda não encontrada (indica order_id ou order_number).');
        }

        $status = (string) $request->get('payment_status');

        if (! in_array($status, self::ALLOWED, true)) {
            return Response::error('Por aqui só se marca como pago ou pendente. Falhas e reembolsos fazem-se no backoffice.');
        }

        $data = $this->runner->validate(UpdateOrderPaymentRequest::class, [
            'payment_status' => $status,
            'payment_method' => $request->get('payment_method'),
            'note' => $request->get('note'),
        ], $user, ['order' => $order]);

        if ($order->payment_status === $status && empty($data['payment_method'])) {
            return Response::error("O pagamento já está como {$status}.");
        }

        $before = ['payment_status' => $order->payment_status, 'payment_method' => $order->payment_method, 'status' => $order->status];

        try {
            if (! empty($data['payment_method'])) {
                $order->update(['payment_method' => $data['payment_method']]);
            }

            $order = $this->orders->setPaymentStatus($order, $status, $user, $data['note'] ?? null);
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }

        $order->refresh()->load(['items', 'statusHistories', 'tags']);
        $this->changes = [
            'order_id' => $order->id,
            'before' => $before,
            'after' => ['payment_status' => $order->payment_status, 'payment_method' => $order->payment_method, 'status' => $order->status],
        ];

        return Response::structured([
            'message' => "Pagamento de {$order->order_number}: {$before['payment_status']} → {$order->payment_status}; a encomenda está em {$order->status}.",
            'order' => OrderPresenter::detail($order),
        ]);
    }
}
