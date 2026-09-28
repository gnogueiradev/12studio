<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\Presenters\OrderPresenter;
use App\Mcp\Tools\StudioTool;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('order_get')]
#[Title('Ver encomenda')]
#[Description('Detalhe de uma encomenda: artigos (com personalização e estado de produção), totais, pagamento, envio, histórico de estados e os próximos estados possíveis. Os contactos do cliente vêm mascarados e a morada não é mostrada (só a localidade). Indica a encomenda pelo id ou pelo número.')]
#[IsReadOnly]
#[IsIdempotent]
class OrderGetTool extends StudioTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id da encomenda.'),
            'order_number' => $schema->string()->max(50)->description('Número da encomenda (em alternativa ao id).'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'required_without:order_number'],
            'order_number' => ['nullable', 'string', 'max:50'],
        ]);

        $order = Order::query()
            ->with(['items', 'statusHistories', 'tags'])
            ->when(
                isset($data['id']),
                fn ($query) => $query->whereKey($data['id']),
                fn ($query) => $query->where('order_number', $data['order_number']),
            )
            ->first();

        if ($order === null) {
            return Response::error('Encomenda não encontrada.');
        }

        return Response::structured(OrderPresenter::detail($order));
    }
}
