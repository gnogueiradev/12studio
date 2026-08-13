<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Address;
use App\Models\Order;
use App\Models\Tag;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\TagService;
use App\Support\ShortDate;
use Carbon\CarbonImmutable;
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
        private TagService $tagService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'customer_type' => (string) $request->query('customer_type', ''),
            'sales_channel' => (string) $request->query('sales_channel', ''),
            'tag' => (string) $request->query('tag', ''),
        ];

        // Base com todos os filtros MENOS o tipo, pela mesma razao das chips de
        // estado das encomendas: se a contagem respeitasse o proprio filtro,
        // todas as chips exceto a ativa mostrariam zero.
        $scoped = fn () => User::query()
            ->where('is_admin', false)
            ->when($filters['search'] !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$filters['search']}%")
                ->orWhere('email', 'like', "%{$filters['search']}%")
                ->orWhere('nif', 'like', "%{$filters['search']}%")))
            // "Tem pelo menos uma encomenda neste canal" — nao e o mesmo que o
            // canal habitual da coluna, que e o mais frequente. Filtrar pelo
            // habitual escondia o cliente que comprou uma vez pelo Instagram.
            ->when($filters['sales_channel'] !== '', fn ($query) => $query->whereHas(
                'orders',
                fn ($order) => $order->where('sales_channel', $filters['sales_channel']),
            ))
            // Dentro do $scoped, como a pesquisa: filtrar por etiqueta tem de
            // reduzir as contagens das chips de tipo.
            ->when($filters['tag'] !== '', fn ($query) => $query->whereHas(
                'tags',
                fn ($tag) => $tag->where('slug', $filters['tag']),
            ));

        $typeCounts = $scoped()
            ->toBase()
            ->selectRaw('customer_type, count(*) as aggregate')
            ->groupBy('customer_type')
            ->pluck('aggregate', 'customer_type')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $customers = $scoped()
            ->when($filters['customer_type'] !== '', fn ($query) => $query->where('customer_type', $filters['customer_type']))
            ->with(['addresses', 'tags'])
            ->withCount('orders')
            // Total gasto = so o que esta efetivamente pago; o indicador
            // financeiro e payment_status, nunca o status da encomenda.
            ->withSum(
                ['orders as paid_total_cents' => fn ($query) => $query->where('payment_status', 'paid')],
                'total_cents',
            )
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $history = $this->orderHistory($customers->getCollection()->pluck('id')->all());

        $customers->through(function (User $customer) use ($history): array {
            $lastOrderAt = $history[$customer->id]['lastOrderAt'] ?? null;

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'customerType' => $customer->customer_type,
                'nif' => $customer->nif,
                'tags' => $customer->tags->pluck('name')->all(),
                'habitualChannel' => $history[$customer->id]['channel'] ?? null,
                'ordersCount' => $customer->orders_count,
                'paidTotalCents' => (int) ($customer->paid_total_cents ?? 0),
                'lastOrderAt' => $lastOrderAt?->format('Y-m-d H:i'),
                'lastOrderAtShort' => ShortDate::of($lastOrderAt),
                'createdAt' => $customer->created_at?->format('Y-m-d'),
            ];
        });

        return Inertia::render('admin/clientes/index', [
            'customers' => $customers,
            'filters' => $filters,
            'typeCounts' => $typeCounts,
            'stats' => $this->stats(),
            'tagSuggestions' => $this->tagService->suggestions(Tag::SCOPE_CUSTOMER),
            'tagOptions' => $this->tagService->optionsFor(Tag::SCOPE_CUSTOMER),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/clientes/create', [
            'tagSuggestions' => $this->tagService->suggestions(Tag::SCOPE_CUSTOMER),
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $customer = $this->customerService->store($request->validated());

        $this->toast('Cliente criado.');

        // Para onde o admin quer ir a seguir. Fica fora do validated() porque
        // nao e um campo do cliente: e de quem submeteu. O modal da listagem
        // manda 'list' (fica onde estava, a ver o cliente novo na tabela) ou
        // 'order' (a checkbox "Criar encomenda a seguir" — a encomenda manual
        // tem o seu proprio seletor de cliente). A pagina /create nao manda
        // nada e mantem a convencao do backoffice: cai no formulario de edicao.
        return match ((string) $request->input('after')) {
            'order' => to_route('admin.encomendas.create'),
            'list' => to_route('admin.clientes.index'),
            default => to_route('admin.clientes.edit', $customer),
        };
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
                'customerType' => $customer->customer_type,
                'phone' => $customer->phone,
                'nif' => $customer->nif,
                'adminNote' => $customer->admin_note,
                // Nomes e nao ids: o TagInput nunca soube o que e uma chave
                // primaria, e e isso que o torna reutilizavel aqui sem tocar
                // numa linha do componente.
                'tags' => $customer->tags->pluck('name')->all(),
                ...$this->addressFields($address),
                'createdAt' => $customer->created_at?->format('Y-m-d'),
                'canDelete' => ! $customer->orders()->exists(),
            ],
            'tagSuggestions' => $this->tagService->suggestions(Tag::SCOPE_CUSTOMER),
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
     * Canal habitual e ultima compra dos clientes da pagina, numa consulta so.
     *
     * O canal habitual e o mais frequente no historico, nao o mais recente:
     * uma venda avulsa pelo Instagram nao muda o habito de quem compra sempre
     * pela loja. As duas colunas saem da mesma leitura das encomendas, em vez
     * de uma subconsulta correlacionada por linha da tabela.
     *
     * @param  array<int, mixed>  $customerIds
     * @return array<int, array{channel: string, lastOrderAt: CarbonImmutable|null}>
     */
    private function orderHistory(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $history = [];
        $best = [];

        Order::query()
            ->toBase()
            ->whereIn('user_id', $customerIds)
            ->selectRaw('user_id, sales_channel, count(*) as aggregate, max(created_at) as last_at')
            ->groupBy('user_id', 'sales_channel')
            // Empate resolvido pelo nome do canal, para a coluna nao mudar
            // sozinha entre visitas.
            ->orderBy('sales_channel')
            ->get()
            ->each(function (object $row) use (&$history, &$best): void {
                $userId = (int) $row->user_id;
                $channel = (string) $row->sales_channel;
                $count = (int) $row->aggregate;
                $lastAt = $row->last_at === null ? null : CarbonImmutable::parse((string) $row->last_at);

                // A entrada nasce completa. Preencher campo a campo deixava a
                // porta aberta a um cliente com ultima compra e sem canal.
                if (! isset($history[$userId])) {
                    $history[$userId] = ['channel' => $channel, 'lastOrderAt' => $lastAt];
                    $best[$userId] = $count;

                    return;
                }

                // A ultima compra e a mais recente de todos os canais; o canal
                // habitual e o da linha com mais encomendas.
                $previous = $history[$userId]['lastOrderAt'];

                if ($lastAt !== null && ($previous === null || $lastAt->greaterThan($previous))) {
                    $history[$userId]['lastOrderAt'] = $lastAt;
                }

                if ($best[$userId] < $count) {
                    $best[$userId] = $count;
                    $history[$userId]['channel'] = $channel;
                }
            });

        return $history;
    }

    /**
     * Os quatro cartoes do topo. Nao respeitam os filtros de proposito: sao um
     * retrato da base de clientes, como os do painel — nao um resumo da tabela
     * que esta por baixo.
     *
     * @return array<string, int>
     */
    private function stats(): array
    {
        $customers = fn () => User::query()->where('is_admin', false);

        // Valor medio = quanto gastou, em media, um cliente que ja pagou
        // alguma coisa. Clientes sem encomenda paga ficam de fora: incluidos
        // como zero, o numero dizia mais sobre quantos contactos estao na
        // tabela do que sobre o que a loja vende.
        $paidTotals = $customers()
            ->withSum(
                ['orders as paid_total_cents' => fn ($query) => $query->where('payment_status', 'paid')],
                'total_cents',
            )
            ->get()
            ->pluck('paid_total_cents')
            ->filter(fn ($cents): bool => (int) $cents > 0);

        return [
            'total' => $customers()->count(),
            // Recorrente = comprou mais do que uma vez.
            'recurring' => $customers()->has('orders', '>=', 2)->count(),
            'newThisMonth' => $customers()->where('created_at', '>=', CarbonImmutable::now()->startOfMonth())->count(),
            'averagePaidCents' => $paidTotals->isEmpty() ? 0 : (int) round($paidTotals->avg()),
        ];
    }

    /**
     * Um cliente pode nao ter morada — no backoffice cria-se um cliente so com
     * o nome. O formulario recebe sempre as mesmas chaves, vazias quando nao
     * ha.
     *
     * @return array<string, string|null>
     */
    private function addressFields(?Address $address): array
    {
        return [
            'line1' => $address?->line1,
            'line2' => $address?->line2,
            'postalCode' => $address?->postal_code,
            'city' => $address?->city,
            'country' => $address === null ? 'PT' : $address->country,
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
