<?php

namespace App\Http\Controllers\Store;

use App\Customers\CustomerLoginLinks;
use App\Http\Controllers\Controller;
use App\Models\Checkout;
use App\Models\PromoCode;
use App\Models\User;
use App\Models\Variant;
use App\Store\ProductDetail;
use App\Store\Seo;
use App\Support\LocalizedUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A pagina onde o Stripe devolve o cliente (`success_url`). Chega muitas vezes
 * antes do aviso do Stripe, por isso diz "a confirmar" e a pagina atualiza-se
 * sozinha. So le: nao cria nada nem fala com o Stripe, e o link — que pode ser
 * partilhado ou guardado — nunca mostra dados pessoais.
 */
class PurchaseController extends Controller
{
    /** A folga, em minutos, para um aviso do Stripe atrasado antes de dar a sessao por perdida. */
    private const GRACE_MINUTES = 10;

    public function __construct(private CustomerLoginLinks $links) {}

    public function show(Request $request, string $token): Response
    {
        $checkout = Checkout::query()->with('order')->where('token', $token)->first();

        abort_if($checkout === null, 404);

        $state = $this->state($checkout);
        $withOrder = in_array($state, ['paid', 'awaiting_payment'], true);

        $response = Inertia::render('store/purchase', [
            'purchase' => [
                'state' => $state,
                'number' => $checkout->order?->order_number,
                'lines' => array_map(fn (array $line): array => [
                    'name' => (string) $line['product_name'],
                    'label' => $line['variant_label'] ?? null,
                    'qty' => (int) $line['qty'],
                    'lineCents' => (int) $line['line_total_cents'],
                ], $checkout->items_snapshot),
                'subtotalCents' => $checkout->subtotal_cents,
                'discountCents' => $checkout->discount_cents,
                'shippingCents' => $checkout->shipping_cents,
                'totalCents' => $checkout->total_cents,
                'leadDays' => $withOrder ? $this->leadDays($checkout) : null,
                'canSaveAccount' => $withOrder && $this->canSaveAccount($checkout),
            ],
        ])->withViewData(['seo' => Seo::make(
            path: '/compra/'.$token,
            title: trans('store.purchase.title'),
            index: false,
        )])->toResponse($request);

        // Quem tem o link tem a compra: nada de Referer para os links da loja.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * "Guarda os teus dados": o link de entrada vai para o email da compra, e
     * a resposta e sempre a mesma (equipa, conta desativada, o que for), para
     * quem tem o link da compra nao descobrir nada sobre esse email. Caminho
     * fixo e nao `back()`: a pagina nao manda Referer.
     */
    public function saveAccount(Request $request, string $token): RedirectResponse
    {
        $checkout = Checkout::query()->with('order')->where('token', $token)->first();

        abort_if($checkout?->order === null, 404);

        if ($this->canSaveAccount($checkout)) {
            $this->links->send($checkout->order->email);
        }

        $this->toast(trans('store.purchase.save_account_sent'));

        return redirect(LocalizedUrl::path('/compra/'.$token))
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Compra paga ou a espera de pagamento, sem sessao de cliente e com um
     * email que nao seja da equipa. Serve a oferta e o envio.
     */
    private function canSaveAccount(Checkout $checkout): bool
    {
        $email = PromoCode::normalizeEmail((string) $checkout->order?->email);

        if ($email === null
            || ! in_array($this->state($checkout), ['paid', 'awaiting_payment'], true)
            || Auth::guard('customer')->check()) {
            return false;
        }

        return ! User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->where(fn ($query) => $query->where('is_admin', true)->orWhereNotNull('staff_role'))
            ->exists();
    }

    /** Com encomenda manda ela; sem encomenda, o relogio da sessao do Stripe. */
    private function state(Checkout $checkout): string
    {
        $order = $checkout->order;

        if ($order !== null) {
            return match (true) {
                in_array($order->status, ['cancelled', 'refunded'], true), $order->payment_status === 'refunded' => 'closed',
                $order->isPaid(), $order->payment_status === 'partially_refunded' => 'paid',
                default => 'awaiting_payment',
            };
        }

        $lapsed = $checkout->status === 'open'
            && $checkout->expires_at !== null
            && $checkout->expires_at->lt(now()->subMinutes(self::GRACE_MINUTES));

        return $checkout->status === 'expired' || $lapsed ? 'expired' : 'confirming';
    }

    /**
     * O prazo mais longo entre as linhas: o das pecas em stock, ou os dias de
     * producao declarados no produto das feitas por encomenda. Null quando
     * nenhuma linha promete nada.
     *
     * @return array{int, int}|null
     */
    private function leadDays(Checkout $checkout): ?array
    {
        $lines = $checkout->items_snapshot;
        $variantIds = array_filter(array_map(
            fn (array $line): mixed => ($line['fulfillment_mode'] ?? null) === 'made_to_order' ? ($line['variant_id'] ?? null) : null,
            $lines,
        ));
        $variants = Variant::query()->with('product')->whereIn('id', $variantIds)->get()->keyBy('id');

        $longest = null;

        foreach ($lines as $line) {
            $days = match ($line['fulfillment_mode'] ?? null) {
                'in_stock' => ProductDetail::READY_DAYS,
                'made_to_order' => ($product = $variants->get($line['variant_id'] ?? 0)?->product) === null
                    ? null
                    : ProductDetail::leadDays($product, 'made_to_order'),
                default => null,
            };

            if ($days !== null && ($longest === null || $days[1] > $longest[1])) {
                $longest = $days;
            }
        }

        return $longest;
    }
}
