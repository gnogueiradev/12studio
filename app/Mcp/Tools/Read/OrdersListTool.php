<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\Presenters\OrderPresenter;
use App\Mcp\Tools\Concerns\Paginates;
use App\Mcp\Tools\StudioTool;
use App\Models\Order;
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

#[Name('orders_list')]
#[Title('Listar encomendas')]
#[Description('Encomendas, mais recentes primeiro, com estado, pagamento, canal e total. O cliente aparece só com o primeiro nome e a inicial. Filtra por estado, estado do pagamento, canal de venda, intervalo de datas ou número da encomenda. Para ver os artigos, usa order_get.')]
#[IsReadOnly]
#[IsIdempotent]
class OrdersListTool extends StudioTool
{
    use Paginates;

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(Order::STATUSES)->description('Só encomendas neste estado.'),
            'payment_status' => $schema->string()->enum(Order::PAYMENT_STATUSES)->description('Só encomendas com este estado de pagamento.'),
            'sales_channel' => $schema->string()->enum(Order::SALES_CHANNELS)->description('Só encomendas deste canal.'),
            'from' => $schema->string()->description('Data inicial (AAAA-MM-DD), inclusive.'),
            'to' => $schema->string()->description('Data final (AAAA-MM-DD), inclusive.'),
            'search' => $schema->string()->max(50)->description('Parte do número da encomenda.'),
            ...$this->paginationSchema($schema),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', Order::STATUSES)],
            'payment_status' => ['nullable', 'in:'.implode(',', Order::PAYMENT_STATUSES)],
            'sales_channel' => ['nullable', 'in:'.implode(',', Order::SALES_CHANNELS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::PER_PAGE_MAX],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        $query = Order::query()
            ->withCount('items')
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['payment_status']), fn (Builder $query) => $query->where('payment_status', $filters['payment_status']))
            ->when(isset($filters['sales_channel']), fn (Builder $query) => $query->where('sales_channel', $filters['sales_channel']))
            ->when(isset($filters['from']), fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['to']))
            // So o numero: procurar por nome ou email daria ao modelo uma forma
            // de confirmar dados pessoais que a resposta nao mostra.
            ->when($search !== '', fn (Builder $query) => $query->where('order_number', 'like', "%{$search}%"))
            ->latest('id');

        $page = $this->paginate($query, $request);

        return Response::structured([
            'orders' => collect($page->items())
                ->map(fn (Order $order): array => OrderPresenter::summary($order))
                ->all(),
            'pagination' => $this->pageMeta($page),
        ]);
    }
}
