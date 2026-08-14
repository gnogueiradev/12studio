<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreManualOrderRequest;
use App\Http\Requests\Order\UpdateOrderAdjustmentRequest;
use App\Http\Requests\Order\UpdateOrderDetailsRequest;
use App\Http\Requests\Order\UpdateOrderPaymentRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Models\OrderDraft;
use App\Models\Tag;
use App\Services\OrderService;
use App\Services\TagService;
use App\Support\ManualOrderOptions;
use App\Support\Money;
use App\Support\OrderPresenter;
use App\Support\ShortDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orderService,
        private TagService $tagService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'payment_status' => (string) $request->query('payment_status', ''),
            'sales_channel' => (string) $request->query('sales_channel', ''),
            'tag' => (string) $request->query('tag', ''),
        ];

        // Base com todos os filtros MENOS o estado. As chips de estado contam
        // sobre esta: se a contagem respeitasse o proprio filtro de estado,
        // todas as chips exceto a ativa mostrariam zero e deixariam de servir
        // para navegar.
        $scoped = fn () => Order::query()
            ->when($filters['payment_status'] !== '', fn ($query) => $query->where('payment_status', $filters['payment_status']))
            ->when($filters['sales_channel'] !== '', fn ($query) => $query->where('sales_channel', $filters['sales_channel']))
            ->when($filters['search'] !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('order_number', 'like', "%{$filters['search']}%")
                ->orWhere('customer_name', 'like', "%{$filters['search']}%")
                ->orWhere('email', 'like', "%{$filters['search']}%")))
            // Dentro do $scoped, como a pesquisa: filtrar por etiqueta tem de
            // reduzir as contagens das chips de estado.
            ->when($filters['tag'] !== '', fn ($query) => $query->whereHas(
                'tags',
                fn ($tag) => $tag->where('slug', $filters['tag']),
            ));

        $statusCounts = $scoped()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $orders = $scoped()
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            // Os artigos servem o resumo da linha ("Vaso ondulado · 2 un.").
            // Substituem o withCount('items'): com a colecao carregada, tanto a
            // contagem como a soma das quantidades saem da mesma leitura.
            ->with([
                'items' => fn ($query) => $query->select('id', 'order_id', 'product_name', 'qty')->orderBy('id'),
                'tags',
            ])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Order $order): array => [
                'id' => $order->id,
                'orderNumber' => $order->order_number,
                'customerName' => $order->customer_name,
                'email' => $order->email,
                'status' => $order->status,
                'paymentStatus' => $order->payment_status,
                'salesChannel' => $order->sales_channel,
                'totalCents' => $order->total_cents,
                'stockIssue' => $order->stock_issue,
                'itemsCount' => $order->items->count(),
                'itemsSummary' => $order->items->first()?->product_name,
                'itemsQty' => (int) $order->items->sum('qty'),
                'tags' => $order->tags->pluck('name')->all(),
                'createdAt' => $order->created_at?->format('Y-m-d H:i'),
                'createdAtShort' => ShortDate::of($order->created_at),
            ]);

        return Inertia::render('admin/encomendas/index', [
            'orders' => $orders,
            'filters' => $filters,
            'statusCounts' => $statusCounts,
            'draftsCount' => OrderDraft::query()
                ->where('created_by_user_id', $request->user()?->getKey())
                ->count(),
            'tagOptions' => $this->tagService->optionsFor(Tag::SCOPE_ORDER),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/encomendas/create', ManualOrderOptions::props());
    }

    public function store(StoreManualOrderRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $order = $this->orderService->createManual($data, $request->user());
        } catch (RuntimeException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return back()->withInput();
        }

        // O rascunho cumpriu o seu papel. Deixa-lo para tras era pô-lo a
        // convidar a criar a mesma encomenda outra vez.
        if (($data['draft_id'] ?? null) !== null) {
            OrderDraft::query()
                ->where('created_by_user_id', $request->user()?->getKey())
                ->whereKey($data['draft_id'])
                ->delete();
        }

        // A checkbox so aparece quando ha email, mas o pedido vem de fora e a
        // guarda tem de estar aqui: uma venda em maos nao tem para onde enviar.
        if ($request->boolean('send_confirmation') && $order->email !== null) {
            Mail::to($order->email)->send(new OrderConfirmationMail($order));
        }

        $this->toast("Encomenda {$order->order_number} criada.");

        return to_route('admin.encomendas.show', $order);
    }

    public function show(Order $order): Response
    {
        return Inertia::render('admin/encomendas/show', [
            'order' => OrderPresenter::detail($order),
            'tagSuggestions' => $this->tagService->suggestions(Tag::SCOPE_ORDER),
        ]);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): RedirectResponse
    {
        try {
            $this->orderService->transitionOrder(
                $order,
                $request->string('status')->value(),
                $request->user(),
                $request->input('note'),
                $request->boolean('force'),
            );
        } catch (RuntimeException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return back();
        }

        $this->toast('Estado atualizado.');

        return back();
    }

    public function updatePayment(UpdateOrderPaymentRequest $request, Order $order): RedirectResponse
    {
        if ($request->filled('payment_method')) {
            $order->update(['payment_method' => $request->string('payment_method')->value()]);
        }

        try {
            $this->orderService->setPaymentStatus(
                $order,
                $request->string('payment_status')->value(),
                $request->user(),
                $request->input('note'),
            );
        } catch (RuntimeException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return back();
        }

        $this->toast('Pagamento atualizado.');

        return back();
    }

    public function updateAdjustment(UpdateOrderAdjustmentRequest $request, Order $order): RedirectResponse
    {
        try {
            $this->orderService->setAdjustment(
                $order,
                Money::fromDecimal($request->string('adjustment_price')->value()),
                $request->input('adjustment_reason'),
                $request->user(),
            );
        } catch (RuntimeException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return back();
        }

        $this->toast('Total atualizado.');

        return back();
    }

    public function updateDetails(UpdateOrderDetailsRequest $request, Order $order): RedirectResponse
    {
        $this->orderService->updateDetails($order, $request->validated());

        $this->toast('Encomenda atualizada.');

        return back();
    }
}
