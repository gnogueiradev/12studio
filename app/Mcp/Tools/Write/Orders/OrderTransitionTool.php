<?php

namespace App\Mcp\Tools\Write\Orders;

use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Presenters\OrderPresenter;
use App\Mcp\Tools\Write\Orders\Concerns\FindsOrders;
use App\Mcp\Tools\WriteTool;
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

#[Name('order_transition')]
#[Title('Avançar encomenda')]
#[Description('Avança uma encomenda no percurso: paid → in_production → ready_to_ship → shipped → delivered (pode saltar degraus, nunca recuar). Só avança depois de paga; para avançar com o pagamento pendente usa "force": true com uma nota. Passar a "shipped" envia ao cliente o email de expedição. Cancelar e reembolsar fazem-se no backoffice. Vê os próximos estados possíveis em order_get (next_statuses).')]
#[IsDestructive(false)]
class OrderTransitionTool extends WriteTool
{
    use FindsOrders;

    /** Cancelar e reembolsar ficam no backoffice na v1: mexem em stock e em dinheiro. */
    public const ALLOWED = ['in_production', 'ready_to_ship', 'shipped', 'delivered'];

    public function __construct(
        private FormRequestRunner $runner,
        private OrderService $orders,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->orderLookupSchema($schema),
            'status' => $schema->string()->enum(self::ALLOWED)->required(),
            'note' => $schema->string()->max(300)->description('Nota para o histórico (obrigatória com force).'),
            'force' => $schema->boolean()->description('Avançar com o pagamento ainda pendente (exige nota).'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $order = $this->findOrder($request);

        if ($order === null) {
            return Response::error('Encomenda não encontrada (indica order_id ou order_number).');
        }

        $status = (string) $request->get('status');

        if (! in_array($status, self::ALLOWED, true)) {
            return Response::error('Por aqui só se avança a encomenda ('.implode(', ', self::ALLOWED).'). Cancelar e reembolsar faz-se no backoffice.');
        }

        $data = $this->runner->validate(UpdateOrderStatusRequest::class, [
            'status' => $status,
            'note' => $request->get('note'),
            'force' => $request->boolean('force'),
        ], $user, ['order' => $order]);

        if ($order->status === $status) {
            return Response::error("A encomenda já está em {$status}.");
        }

        $before = $order->status;

        try {
            $order = $this->orders->transitionOrder($order, $status, $user, $data['note'] ?? null, (bool) ($data['force'] ?? false));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }

        $order->load(['items', 'statusHistories', 'tags']);
        $this->changes = ['order_id' => $order->id, 'before' => ['status' => $before], 'after' => ['status' => $order->status]];

        return Response::structured([
            'message' => "Encomenda {$order->order_number}: {$before} → {$order->status}."
                .($order->status === 'shipped' && $order->email !== null ? ' Email de expedição enviado ao cliente.' : ''),
            'order' => OrderPresenter::detail($order),
        ]);
    }
}
