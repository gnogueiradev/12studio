<?php

namespace App\Http\Controllers\Store;

use App\Customers\CustomerDirectory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\UpdateCustomerDetailsRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Shop\CartCookie;
use App\Shop\CartService;
use App\Shop\Reorder;
use App\Store\Seo;
use App\Support\LocalizedUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A conta do cliente (guarda `customer`): as encomendas ligadas a ela, os
 * dados de entrega, o interruptor das ofertas e o "comprar outra vez".
 * Tudo sai sem indexar e sem Referer — são dados pessoais. Por isso também as
 * respostas das escritas vão para um caminho fixo e não para `back()`: sem
 * Referer, o `back()` não sabia voltar.
 */
class CustomerAccountController extends Controller
{
    public function __construct(
        private CustomerDirectory $customers,
        private CartService $carts,
        private Reorder $reorder,
    ) {}

    public function show(Request $request): Response
    {
        $user = $this->user();
        $address = $user->addresses()->where('is_default', true)->first();

        return $this->private(Inertia::render('store/account/index', [
            'account' => [
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'phone' => (string) $user->phone,
                'nif' => (string) $user->nif,
                'line1' => (string) $address?->line1,
                'line2' => (string) $address?->line2,
                'postalCode' => (string) $address?->postal_code,
                'city' => (string) $address?->city,
                'offers' => $user->marketing_opt_out_at === null,
            ],
            'orders' => Order::query()
                ->where('user_id', $user->id)
                ->latest()
                ->latest('id')
                ->get()
                ->map(fn (Order $order): array => [
                    'number' => $order->order_number,
                    'date' => $order->created_at?->toIso8601String(),
                    'totalCents' => $order->total_cents,
                    'status' => $order->status,
                    'tracking' => $this->tracking($order),
                ])
                ->all(),
        ])->withViewData(['seo' => Seo::make(path: '/conta', title: trans('store.account.title'), index: false)])
            ->toResponse($request));
    }

    /** Só as encomendas desta conta: de outra, 404 como se não existisse. */
    public function order(Request $request, string $number): Response
    {
        $order = $this->owned($number);

        return $this->private(Inertia::render('store/account/order', [
            'order' => [
                'number' => $order->order_number,
                'date' => $order->created_at?->toIso8601String(),
                'status' => $order->status,
                'tracking' => $this->tracking($order),
                'lines' => $order->items->map(fn (OrderItem $item): array => [
                    'name' => $item->product_name,
                    'label' => $item->variant_label,
                    'qty' => $item->qty,
                    'lineCents' => $item->line_total_cents,
                    'personalization' => collect($item->personalization ?? [])
                        ->map(fn (mixed $value, string|int $key): array => [
                            'label' => Str::headline((string) $key),
                            'value' => (string) $value,
                        ])->values()->all(),
                ])->all(),
                'address' => $this->addressLines($order),
                'subtotalCents' => $order->subtotal_cents,
                'discountCents' => $order->discount_cents,
                'shippingCents' => $order->shipping_cents,
                'totalCents' => $order->total_cents,
                'canReorder' => $order->items->contains(fn (OrderItem $item): bool => $item->variant_id !== null),
            ],
        ])->withViewData(['seo' => Seo::make(path: '/conta', title: trans('store.account.title'), index: false)])
            ->toResponse($request));
    }

    public function updateDetails(UpdateCustomerDetailsRequest $request): Response
    {
        $this->customers->saveDetails($this->user(), $request->customer(), $request->address());
        $this->toast(trans('store.account.details_saved'));

        return $this->private(redirect(LocalizedUrl::path('/conta')));
    }

    /** `enabled` é o interruptor tal como o cliente o vê: ligado = recebe ofertas. */
    public function updateOffers(Request $request): Response
    {
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        // forceFill: a recusa não está no #[Fillable] da conta.
        $this->user()->forceFill(['marketing_opt_out_at' => $enabled ? null : now()])->save();
        $this->toast(trans($enabled ? 'store.account.offers_on' : 'store.account.offers_off'));

        return $this->private(redirect(LocalizedUrl::path('/conta')));
    }

    /**
     * Põe no carrinho o que a encomenda levou, ao preço de hoje, e segue para
     * ele. O que já não se vende fica de fora e avisa-se pelo nome; se nada
     * entrou, não se cria carrinho nenhum.
     */
    public function reorder(Request $request, string $number): Response
    {
        $order = $this->owned($number);

        ['cart' => $cart, 'skipped' => $skipped] = $this->reorder->into($this->carts->fromRequest($request), $order);

        if ($cart === null) {
            $this->toast(trans('store.account.reorder_nothing'), 'error');

            return $this->private(redirect(LocalizedUrl::path('/conta/encomendas/'.$order->order_number)));
        }

        if ($skipped !== []) {
            $this->toast(trans('store.account.reorder_skipped', ['names' => implode(', ', $skipped)]), 'warning');
        }

        return $this->private(redirect(LocalizedUrl::path('/carrinho'))
            ->withCookie(CartCookie::make($request, $cart)));
    }

    private function user(): User
    {
        $user = Auth::guard('customer')->user();

        return $user instanceof User ? $user : abort(404);
    }

    private function owned(string $number): Order
    {
        return Order::query()
            ->with('items')
            ->where('user_id', $this->user()->id)
            ->where('order_number', $number)
            ->firstOrFail();
    }

    /**
     * O seguimento só se tem número ou endereço; o link só se for http(s): vem
     * do backoffice, e um `javascript:` nunca vai para um href.
     *
     * @return array{number: string|null, url: string|null}|null
     */
    private function tracking(Order $order): ?array
    {
        $url = $order->tracking_url !== null && preg_match('#^https?://#i', $order->tracking_url) === 1
            ? $order->tracking_url
            : null;

        if ($order->tracking_number === null && $url === null) {
            return null;
        }

        return ['number' => $order->tracking_number, 'url' => $url];
    }

    /**
     * A morada de envio em linhas, para ler.
     *
     * @return list<string>|null
     */
    private function addressLines(Order $order): ?array
    {
        $address = $order->shipping_address;

        if ($address === null) {
            return null;
        }

        return array_values(array_filter([
            $address['name'] ?? null,
            $address['line1'] ?? null,
            $address['line2'] ?? null,
            trim(($address['postalCode'] ?? '').' '.($address['city'] ?? '')),
        ], fn (mixed $line): bool => is_string($line) && $line !== ''));
    }

    private function private(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
