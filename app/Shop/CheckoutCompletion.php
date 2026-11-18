<?php

namespace App\Shop;

use App\Mail\OrderConfirmationMail;
use App\Models\Cart;
use App\Models\Checkout;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * A encomenda de uma compra da loja. Nasce uma vez por `checkout`, do retrato
 * congelado no "Pagar", quando o Stripe avisa que o cliente pagou (ou que
 * gerou a referencia Multibanco).
 *
 * A ancora e o proprio `checkout`: ganha o `order_id` na mesma transacao em
 * que a encomenda nasce, e quem chega depois — o mesmo aviso repetido, ou
 * outro aviso da mesma sessao — encontra-a la e nao cria outra.
 *
 * Um pagamento tem sempre encomenda, seja qual for o estado do `checkout`:
 * um expirado tambem, que o cliente pode ter pago a sessao que ja davamos
 * por perdida.
 */
class CheckoutCompletion
{
    /** O bastante para criar uma encomenda; se o processo morrer, o tranco solta-se sozinho. */
    private const LOCK_SECONDS = 30;

    public function __construct(
        private OrderService $orders,
        private CartService $carts,
    ) {}

    /**
     * A encomenda deste `checkout`. Sem `$create`, so a procura.
     *
     * @throws RuntimeException quando outro pedido esta a criar a mesma
     *                          encomenda: o aviso falha e o Stripe repete-o
     */
    public function orderFor(Checkout $checkout, bool $create): ?Order
    {
        if (! $create) {
            return $checkout->fresh()?->order;
        }

        $lock = Cache::lock("shop-complete:{$checkout->id}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new RuntimeException("A encomenda da compra {$checkout->id} já está a ser criada.");
        }

        try {
            $order = $this->orderForLocked($checkout);
        } catch (Throwable $exception) {
            $lock->release();

            throw $exception;
        }

        // So depois do commit: soltar antes deixava outro pedido ler o
        // checkout ainda sem encomenda (a escrita deste ainda nao se via) e
        // criar a segunda. Se a transacao se desfizer, o afterCommit nao
        // corre: com a cache `database` sem ligacao propria para os trancos
        // (a configuracao de hoje), a linha do tranco foi escrita nessa mesma
        // transacao e desaparece com ela. O prazo so conta com os trancos
        // noutra ligacao.
        DB::afterCommit(fn () => $lock->release());

        return $order;
    }

    private function orderForLocked(Checkout $checkout): ?Order
    {
        // Relido debaixo do tranco: o que o chamador tem pode ser de antes de
        // outro aviso ter criado a encomenda.
        $checkout = Checkout::query()->findOrFail($checkout->id);

        // A ancora e o order_id. Se aponta para uma encomenda que ja nao
        // existe, criar outra era cobrar a mesma compra duas vezes no
        // backoffice: fica no relatorio de erros para alguem ver.
        if ($checkout->order_id !== null) {
            if ($checkout->order === null) {
                report(new RuntimeException("A compra {$checkout->id} aponta para a encomenda {$checkout->order_id}, que já não existe. Nada foi criado."));
            }

            return $checkout->order;
        }

        // Tudo ou nada, mesmo fora da transacao do aviso: a encomenda, a
        // ligacao ao checkout, o carrinho vazio e o email na fila.
        return DB::transaction(fn (): Order => $this->create($checkout));
    }

    private function create(Checkout $checkout): Order
    {
        $customer = $checkout->customer ?? [];

        $order = $this->orders->createFromStore([
            'customer_name' => (string) ($customer['name'] ?? ''),
            'email' => (string) ($customer['email'] ?? ''),
            'phone' => $customer['phone'] ?? null,
            'nif' => $customer['nif'] ?? null,
            'shipping_address' => $checkout->shipping_address,
            'subtotal_cents' => $checkout->subtotal_cents,
            'shipping_cents' => $checkout->shipping_cents,
            'discount_cents' => $checkout->discount_cents,
            'promo_code_id' => $checkout->promo_code_id,
            'promo_code' => $checkout->promo_code,
            'total_cents' => $checkout->total_cents,
            'note' => 'Compra na loja online.',
            'lines' => $checkout->items_snapshot,
        ], function (Order $order) use ($checkout): void {
            // Os dados pessoais passam a viver na encomenda: o checkout, que
            // fica como rasto dela, deixa de os guardar (a pagina /compra so
            // le as linhas e os totais).
            $checkout->update([
                'order_id' => $order->getKey(),
                'status' => 'completed',
                'customer' => null,
                'shipping_address' => null,
            ]);
        });

        // O carrinho pode ja ter sido limpo pelo prune: nesse caso nao ha o
        // que esvaziar.
        $cart = Cart::query()->find($checkout->cart_id);

        if ($cart !== null) {
            $this->carts->clear($cart);
        }

        // Queued e afterCommit: sai so se a encomenda ficar, uma vez.
        if ($order->email !== null) {
            Mail::to($order->email)->send(new OrderConfirmationMail($order));
        }

        return $order;
    }
}
