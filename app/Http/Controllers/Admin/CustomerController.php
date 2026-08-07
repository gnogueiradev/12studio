<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cliente = User com is_admin = false. A pagina `edit` acumula os dois
 * papeis (formulario + historico de encomendas), seguindo a convencao do
 * backoffice de nao ter rotas `show`.
 */
class CustomerController extends Controller
{
    public function __construct(
        private CustomerService $customerService,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $customers = User::query()
            ->where('is_admin', false)
            ->with('addresses')
            ->withCount('orders')
            // Total gasto = so o que esta efetivamente pago; o indicador
            // financeiro e payment_status, nunca o status da encomenda.
            ->withSum(
                ['orders as paid_total_cents' => fn ($query) => $query->where('payment_status', 'paid')],
                'total_cents',
            )
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('addresses', fn ($address) => $address->where('nif', 'like', "%{$search}%"))))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'city' => $customer->addresses->first()?->city,
                'ordersCount' => $customer->orders_count,
                'paidTotalCents' => (int) ($customer->paid_total_cents ?? 0),
                'createdAt' => $customer->created_at?->format('Y-m-d'),
            ]);

        return Inertia::render('admin/clientes/index', [
            'customers' => $customers,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/clientes/create');
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $customer = $this->customerService->store($request->validated());

        $this->toast('Cliente criado.');

        return to_route('admin.clientes.edit', $customer);
    }

    public function edit(User $customer): Response
    {
        $this->ensureIsCustomer($customer);

        // Da coleccao, nao do builder: um cliente pode nao ter morada.
        $address = $customer->addresses->first();

        return Inertia::render('admin/clientes/edit', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                ...$this->addressFields($address),
                'createdAt' => $customer->created_at?->format('Y-m-d'),
                'canDelete' => ! $customer->orders()->exists(),
            ],
            'orders' => $customer->orders()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Order $order): array => [
                    'id' => $order->id,
                    'orderNumber' => $order->order_number,
                    'status' => $order->status,
                    'paymentStatus' => $order->payment_status,
                    'salesChannel' => $order->sales_channel,
                    'totalCents' => $order->total_cents,
                    'createdAt' => $order->created_at?->format('Y-m-d'),
                ]),
        ]);
    }

    public function update(UpdateCustomerRequest $request, User $customer): RedirectResponse
    {
        $this->ensureIsCustomer($customer);

        $this->customerService->update($customer, $request->validated());

        $this->toast('Cliente atualizado.');

        return to_route('admin.clientes.edit', $customer);
    }

    /**
     * Hard delete so sem encomendas; com historico comercial o registo fica.
     */
    public function destroy(User $customer): RedirectResponse
    {
        $this->ensureIsCustomer($customer);

        if (! $this->customerService->delete($customer)) {
            $this->toast('Este cliente tem encomendas — o registo não pode ser apagado.', 'error');

            return back();
        }

        $this->toast('Cliente apagado.');

        return to_route('admin.clientes.index');
    }

    /**
     * Um cliente pode nao ter morada (criado antes, ou importado). O
     * formulario recebe sempre as mesmas chaves — vazias quando nao ha.
     *
     * @return array<string, string|null>
     */
    private function addressFields(?Address $address): array
    {
        if ($address === null) {
            return [
                'line1' => null,
                'line2' => null,
                'postalCode' => null,
                'city' => null,
                'country' => 'PT',
                'phone' => null,
                'nif' => null,
            ];
        }

        return [
            'line1' => $address->line1,
            'line2' => $address->line2,
            'postalCode' => $address->postal_code,
            'city' => $address->city,
            'country' => $address->country,
            'phone' => $address->phone,
            'nif' => $address->nif,
        ];
    }

    /**
     * A rota resolve qualquer User; administradores nao se gerem por aqui.
     */
    private function ensureIsCustomer(User $customer): void
    {
        abort_if($customer->isAdmin(), 404);
    }
}
