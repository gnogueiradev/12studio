<?php

namespace App\Shop;

use App\Customers\CustomerDirectory;
use App\Mail\OrderConfirmationMail;
use App\Models\Cart;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\User;
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
        private CustomerDirectory $customers,
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
        // Lido antes de o callback abaixo limpar o checkout.
        $missingCustomer = $checkout->customer === null;

        // Ainda com o retrato inteiro: o callback abaixo apaga-o. Dentro da
        // mesma transacao, por isso uma falha aqui desfaz tudo e o Stripe repete.
        $account = $missingCustomer ? null : $this->account($checkout, $customer);

        $order = $this->orders->createFromStore([
            'user_id' => $account?->id,
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

            $this->clearLeftovers($checkout);
        });

        // Nao devia acontecer (so se limpam os dados de quem ja nao se paga),
        // mas o pagamento nao depende disso: o dinheiro chegou, por isso a
        // encomenda cria-se — falhar o aviso so fazia o Stripe repeti-lo —,
        // e fica dito, sem dados pessoais, onde ir buscar o cliente.
        if ($missingCustomer) {
            report(new RuntimeException("A compra {$checkout->id} (sessão {$checkout->stripe_session_id}) foi paga sem dados do cliente. A encomenda {$order->order_number} foi criada sem nome, email nem morada."));
            $this->orders->notePayment($order, 'Dados do cliente em falta: confirma-os no pagamento, no Dashboard do Stripe.');
        }

        // O carrinho pode ja ter sido limpo pelo prune: nesse caso nao ha o
        // que esvaziar.
        $cart = Cart::query()->find($checkout->cart_id);

        if ($cart !== null) {
            $this->carts->clear($cart);
        }

        // Queued e afterCommit: sai so se a encomenda ficar, uma vez.
        if (filled($order->email)) {
            Mail::to($order->email)->send(new OrderConfirmationMail($order));
        }

        return $order;
    }

    /**
     * O cliente a quem a compra pertence. Quem entrou na conta ao pagar
     * guarda os dados e a escolha de marketing (a caixa desmarcada volta a
     * aceitar ofertas); quem comprou sem entrar so pode recusar, nunca voltar
     * a aceitar: nao se prova que o email e dele. Sem escolha (`null`, um
     * pedido sem a caixa) a conta fica como esta. Equipa ou email ambiguo:
     * sem cliente, a encomenda fica sem conta.
     *
     * @param  array<string, mixed>  $customer
     */
    private function account(Checkout $checkout, array $customer): ?User
    {
        $optOut = $checkout->marketing_opt_out;
        $signedIn = $checkout->user_id === null ? null : User::query()->find($checkout->user_id);

        if ($signedIn !== null && ! $signedIn->isStaff()) {
            $this->customers->saveDetails($signedIn, $customer, $checkout->shipping_address ?? []);
            // Recusar guarda a data da primeira recusa; a caixa desmarcada so
            // desfaz uma recusa de antes do "Pagar": o aviso do Stripe pode
            // chegar dias depois (o Multibanco), e uma recusa feita entretanto,
            // pelo link de um email ou na conta, e a mais recente. forceFill:
            // a recusa nao esta no #[Fillable] da conta.
            $refusedAt = $signedIn->marketing_opt_out_at;

            if ($optOut === true && $refusedAt === null) {
                $signedIn->forceFill(['marketing_opt_out_at' => now()])->save();
            } elseif ($optOut === false && $refusedAt?->lessThanOrEqualTo($checkout->created_at)) {
                $signedIn->forceFill(['marketing_opt_out_at' => null])->save();
            }

            return $signedIn;
        }

        $user = $this->customers->forPurchase((string) ($customer['email'] ?? ''), $customer, $checkout->shipping_address ?? []);

        if ($user !== null && $optOut === true && $user->marketing_opt_out_at === null) {
            $user->forceFill(['marketing_opt_out_at' => now()])->save();
        }

        return $user;
    }

    /**
     * Os outros checkouts do carrinho sem encomenda sao sobras da mesma
     * tentativa de compra (um "Pagar" repetido): nao ficam com os dados
     * pessoais. So conta o prazo da sessao, nao o `status` (que e o que nos
     * acreditamos): dentro da vida dela o Stripe ainda a pode cobrar, e um
     * pagamento tardio criaria a encomenda a partir do que o checkout guarda,
     * que sem dados nasceria vazia. Sem `expires_at` nunca se abriu sessao.
     */
    private function clearLeftovers(Checkout $paid): void
    {
        if ($paid->cart_id === null) {
            return;
        }

        Checkout::query()
            ->where('cart_id', $paid->cart_id)
            ->whereKeyNot($paid->getKey())
            ->whereNull('order_id')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '<=', now()))
            ->update(['customer' => null, 'shipping_address' => null]);
    }
}
