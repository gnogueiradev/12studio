<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Stripe\StripeCheckout;
use App\Stripe\StripeClient;
use App\Stripe\StripeException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * O link de pagamento que o dono manda ao cliente: /pagar/{token}.
 *
 * O token e o guest_access_token da encomenda — 64 caracteres aleatorios com
 * que ela ja nasce. Quem tem o link ve o resumo e paga; nao ve morada, email
 * nem telefone, porque o link anda por conversas de Instagram.
 *
 * Abrir o link nao cria nada no Stripe: o Instagram e o Facebook abrem-no
 * sozinhos para a pre-visualizacao. A sessao do Stripe so nasce no botao
 * "Pagar".
 */
class PaymentLinkController extends Controller
{
    public function show(Request $request, StripeClient $stripe, string $token): Response
    {
        $order = $this->order($stripe, $token);
        $order->load('items');

        return Inertia::render('pagar', [
            'order' => [
                'number' => $order->order_number,
                'items' => $order->items->map(fn (OrderItem $item): array => [
                    'name' => $item->product_name,
                    'label' => $item->variant_label,
                    'qty' => $item->qty,
                    'totalCents' => $item->line_total_cents,
                ])->values()->all(),
                'shippingCents' => $order->shipping_cents,
                'adjustmentCents' => $order->adjustment_cents,
                'totalCents' => $order->total_cents,
            ],
            'state' => $this->state($order),
            'returning' => $request->boolean('pago'),
            'token' => $token,
        ]);
    }

    public function pay(StripeClient $stripe, StripeCheckout $checkout, string $token): SymfonyResponse|RedirectResponse
    {
        $order = $this->order($stripe, $token);

        if (! StripeCheckout::payable($order)) {
            return to_route('payment-link.show', $token);
        }

        try {
            $url = $checkout->start($order, route('payment-link.show', $token));
        } catch (StripeException $exception) {
            // O cliente nao precisa de saber o que o Stripe disse; o dono sim.
            report($exception);

            return to_route('payment-link.show', $token)
                ->withErrors(['payment' => 'Não foi possível abrir o pagamento. Tenta outra vez daqui a pouco.']);
        }

        if ($url === StripeCheckout::PROCESSING) {
            return to_route('payment-link.show', $token);
        }

        // Para o Inertia, um 409 que o leva para fora; para um formulario
        // normal, um redirect.
        return Inertia::location($url);
    }

    private function order(StripeClient $stripe, string $token): Order
    {
        abort_unless($stripe->isEnabled(), 404);

        return Order::query()->where('guest_access_token', $token)->firstOrFail();
    }

    /**
     * paid: ja paga. processing: o cliente ja submeteu e o pagamento esta a
     * caminho (uma referencia Multibanco). unavailable: cancelada ou
     * reembolsada. payable: o botao.
     */
    private function state(Order $order): string
    {
        return match (true) {
            in_array($order->payment_status, ['paid', 'partially_refunded'], true) => 'paid',
            ! StripeCheckout::payable($order) => 'unavailable',
            $order->stripe_payment_intent_id !== null => 'processing',
            default => 'payable',
        };
    }
}
