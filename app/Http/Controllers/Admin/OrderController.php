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

        $orders = Order::query()
            ->withCount('items')
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['payment_status'] !== '', fn ($query) => $query->where('payment_status', $filters['payment_status']))
            ->when($filters['sales_channel'] !== '', fn ($query) => $query->where('sales_channel', $filters['sales_channel']))
            ->when($filters['search'] !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('order_number', 'like', "%{$filters['search']}%")
                ->orWhere('customer_name', 'like', "%{$filters['search']}%")
                ->orWhere('email', 'like', "%{$filters['search']}%")))
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
                'itemsCount' => $order->items_count,
                'createdAt' => $order->created_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('admin/encomendas/index', [
            'orders' => $orders,
            'filters' => $filters,
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
