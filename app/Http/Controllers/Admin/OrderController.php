<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreManualOrderRequest;
use App\Http\Requests\Order\UpdateOrderDetailsRequest;
use App\Http\Requests\Order\UpdateOrderPaymentRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use App\Support\OrderPresenter;
use Carbon\CarbonInterface;
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
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'payment_status' => (string) $request->query('payment_status', ''),
            'sales_channel' => (string) $request->query('sales_channel', ''),
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
                ->orWhere('email', 'like', "%{$filters['search']}%")));

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
            ->with(['items' => fn ($query) => $query->select('id', 'order_id', 'product_name', 'qty')->orderBy('id')])
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
                'createdAt' => $order->created_at?->format('Y-m-d H:i'),
                'createdAtShort' => $this->shortDate($order->created_at),
            ]);

        return Inertia::render('admin/encomendas/index', [
            'orders' => $orders,
            'filters' => $filters,
            'statusCounts' => $statusCounts,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/encomendas/create', [
            'customers' => $this->customerOptions(),
            'variants' => $this->variantOptions(),
            'defaultVatRate' => (int) config('shop.default_vat_rate', 23),
        ]);
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

        if ($request->boolean('send_confirmation')) {
            Mail::to($order->email)->send(new OrderConfirmationMail($order));
        }

        $this->toast("Encomenda {$order->order_number} criada.");

        return to_route('admin.encomendas.show', $order);
    }

    public function show(Order $order): Response
    {
        return Inertia::render('admin/encomendas/show', [
            'order' => OrderPresenter::detail($order),
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

    public function updateDetails(UpdateOrderDetailsRequest $request, Order $order): RedirectResponse
    {
        $this->orderService->updateDetails($order, $request->validated());

        $this->toast('Encomenda atualizada.');

        return back();
    }

    /** Abreviaturas PT dos meses, indexadas por numero do mes. */
    private const MONTHS = [
        1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 5 => 'mai', 6 => 'jun',
        7 => 'jul', 8 => 'ago', 9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez',
    ];

    /**
     * "09 ago" para a coluna Data da listagem. O mapa e explicito e nao um
     * translatedFormat() porque o config('app.locale') tem 'en' por omissao e
     * o ambiente de testes nao garante 'pt' — a data da listagem nao pode
     * mudar de idioma consoante o .env. A data completa continua a viajar em
     * `createdAt` e aparece no title da celula.
     */
    private function shortDate(?CarbonInterface $at): ?string
    {
        return $at === null
            ? null
            : $at->format('d').' '.self::MONTHS[(int) $at->format('n')];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customerOptions(): array
    {
        return User::query()
            ->where('is_admin', false)
            ->with('addresses')
            ->orderBy('name')
            ->get()
            ->map(function (User $customer): array {
                $address = $customer->addresses->first();

                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $address?->phone,
                    'nif' => $address?->nif,
                    'address' => $address === null ? null : [
                        'line1' => $address->line1,
                        'line2' => $address->line2,
                        'postalCode' => $address->postal_code,
                        'city' => $address->city,
                        'country' => $address->country,
                    ],
                ];
            })
            ->all();
    }

    /**
     * Variantes ativas de produtos nao arquivados — o que o admin pode pôr
     * numa encomenda hoje.
     *
     * @return array<int, array<string, mixed>>
     */
    private function variantOptions(): array
    {
        return Variant::query()
            ->where('active', true)
            ->whereHas('product', fn ($query) => $query->where('status', '!=', 'archived'))
            ->with(['product', 'color'])
            ->orderBy('sku')
            ->get()
            ->map(fn (Variant $variant): array => [
                'id' => $variant->id,
                'label' => trim(implode(' ', array_filter([
                    $variant->product->name,
                    $variant->color?->name,
                    $variant->size_label,
                ]))),
                'sku' => $variant->sku,
                'priceCents' => $variant->price_cents,
                'wholesalePriceCents' => $variant->wholesale_price_cents,
                'availableStock' => $variant->available_stock,
                'vatRate' => $variant->product->vat_rate,
                'fulfillmentMode' => $variant->product->fulfillment_mode,
                'productName' => $variant->product->name,
            ])
            ->all();
    }
}
