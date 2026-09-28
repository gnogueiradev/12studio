<?php

namespace App\Alerts;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Throwable;

/**
 * #encomendas, e a parte de producao das encomendas em #stock-producao.
 *
 * Os dados do cliente vao completos (nome, email, telefone, morada) — decisao
 * do dono. Cada mensagem leva o link para a encomenda no backoffice.
 */
class OrderAlerts
{
    public function __construct(
        private AlertSender $sender,
    ) {}

    public function created(Order $order, ?User $by): void
    {
        $this->guard(function () use ($order, $by): void {
            $message = DiscordMessage::make("🛒 Nova encomenda {$order->order_number}", DiscordMessage::SUCCESS)
                ->description($this->itemLines($order));

            $this->customerFields($message, $order)
                ->field('Canal', Labels::of(Labels::SALES_CHANNELS, $order->sales_channel))
                ->field('Referência externa', $order->external_order_reference)
                ->field('Total', $this->money($order->total_cents))
                ->field('Pagamento', $this->paymentLabel($order))
                ->field('Registada por', $this->sender->actor($by))
                ->field('Nota', $order->admin_note, inline: false);

            $this->send(AlertChannel::ORDERS, $message, $order);
        });
    }

    /**
     * Pago, parcialmente reembolsado ou de volta a pendente. O falhado e o
     * reembolsado chegam pela transicao que provocam (cancelada/reembolsada),
     * com o motivo — nao em duplicado aqui.
     */
    public function paymentChanged(Order $order, string $previous, ?User $by, ?string $note): void
    {
        if (in_array($order->payment_status, ['failed', 'refunded'], true)) {
            return;
        }

        $this->guard(function () use ($order, $previous, $by, $note): void {
            $level = $order->payment_status === 'paid' ? DiscordMessage::SUCCESS : DiscordMessage::WARNING;
            $title = $order->payment_status === 'paid'
                ? "💶 Pagamento registado — {$order->order_number}"
                : "💶 Pagamento de {$order->order_number}: ".Labels::of(Labels::PAYMENT_STATUSES, $order->payment_status);

            $message = DiscordMessage::make($title, $level)
                ->field('Cliente', $order->customer_name)
                ->field('Total', $this->money($order->total_cents))
                ->field('Antes', Labels::of(Labels::PAYMENT_STATUSES, $previous))
                ->field('Método', Labels::of(Labels::PAYMENT_METHODS, $order->payment_method))
                ->field('Por', $this->sender->actor($by))
                ->field('Nota', $note, inline: false);

            $this->send(AlertChannel::ORDERS, $message, $order);
        });
    }

    public function adjusted(Order $order, int $previousTotal, ?User $by): void
    {
        $this->guard(function () use ($order, $previousTotal, $by): void {
            $message = DiscordMessage::make("✏️ Total ajustado — {$order->order_number}", DiscordMessage::INFO)
                ->field('Cliente', $order->customer_name)
                ->field('Antes', $this->money($previousTotal))
                ->field('Agora', $this->money($order->total_cents))
                ->field('Motivo', $order->adjustment_reason, inline: false)
                ->field('Por', $this->sender->actor($by));

            $this->send(AlertChannel::ORDERS, $message, $order);
        });
    }

    /**
     * So as transicoes que dizem alguma coisa. `paid` e `in_production`
     * nascem do pagamento registado (ja avisado); `ready_to_ship` vai para a
     * producao.
     */
    public function transitioned(Order $order, string $from, string $to, ?User $by, ?string $note, bool $forced): void
    {
        $this->guard(function () use ($order, $from, $to, $by, $note, $forced): void {
            if ($forced) {
                $message = DiscordMessage::make("⚠️ Avançada sem pagamento — {$order->order_number}", DiscordMessage::WARNING)
                    ->description('A encomenda avançou com o pagamento por regularizar.')
                    ->field('Cliente', $order->customer_name)
                    ->field('Total', $this->money($order->total_cents))
                    ->field('De → para', Labels::of(Labels::ORDER_STATUSES, $from).' → '.Labels::of(Labels::ORDER_STATUSES, $to))
                    ->field('Por', $this->sender->actor($by))
                    ->field('Nota', $note, inline: false);

                $this->send(AlertChannel::ORDERS, $message, $order);

                return;
            }

            [$channel, $title, $level] = match ($to) {
                'ready_to_ship' => [AlertChannel::STOCK, "📦 Pronta a enviar — {$order->order_number}", DiscordMessage::SUCCESS],
                'shipped' => [AlertChannel::ORDERS, "🚚 Enviada — {$order->order_number}", DiscordMessage::INFO],
                'delivered' => [AlertChannel::ORDERS, "✅ Entregue — {$order->order_number}", DiscordMessage::SUCCESS],
                'cancelled' => [AlertChannel::ORDERS, "❌ Cancelada — {$order->order_number}", DiscordMessage::DANGER],
                'refunded' => [AlertChannel::ORDERS, "↩️ Reembolsada — {$order->order_number}", DiscordMessage::DANGER],
                default => [null, null, null],
            };

            if ($channel === null) {
                return;
            }

            $message = DiscordMessage::make($title, $level)
                ->field('Cliente', $order->customer_name)
                ->field('Total', $this->money($order->total_cents))
                ->field('Antes', Labels::of(Labels::ORDER_STATUSES, $from))
                ->field('Por', $this->sender->actor($by))
                ->field('Motivo', $note, inline: false);

            if ($to === 'shipped') {
                $message->field('Envio', $order->shipping_method_name)
                    ->field('Tracking', $order->tracking_url ?? $order->tracking_number, inline: false)
                    ->field('Email de envio', $order->email === null ? 'Sem email — cliente não foi avisado' : "Enviado para {$order->email}", inline: false);
            }

            if ($to === 'cancelled') {
                $message->field('Stock', 'Reposto o que tinha saído da prateleira', inline: false);
            }

            if ($to === 'ready_to_ship') {
                $message->description($this->itemLines($order));
                $this->customerFields($message, $order);
            }

            $this->send($channel, $message, $order);
        });
    }

    public function itemProductionChanged(OrderItem $item, string $from, string $to, ?User $by, ?string $note): void
    {
        $this->guard(function () use ($item, $from, $to, $by, $note): void {
            $order = $item->order;
            $backwards = array_search($to, array_keys(Labels::PRODUCTION_STATUSES), true)
                < array_search($from, array_keys(Labels::PRODUCTION_STATUSES), true);

            $icon = match ($to) {
                'printing' => '🖨️',
                'quality_check' => '🔍',
                'ready' => '✅',
                default => '⏳',
            };

            $message = DiscordMessage::make(
                ($backwards ? '↩️ ' : $icon.' ').$this->itemName($item).' — '.Labels::of(Labels::PRODUCTION_STATUSES, $to),
                $backwards ? DiscordMessage::WARNING : DiscordMessage::INFO,
            )
                ->field('Encomenda', $order->order_number)
                ->field('Cliente', $order->customer_name)
                ->field('Antes', Labels::of(Labels::PRODUCTION_STATUSES, $from))
                ->field('Quantidade', (string) $item->qty)
                ->field('Por', $this->sender->actor($by))
                ->field('Nota', $note, inline: false);

            $this->send(AlertChannel::STOCK, $message, $order);
        });
    }

    public function stalePayment(Order $order, int $days): void
    {
        $this->guard(function () use ($order, $days): void {
            $message = DiscordMessage::make("⏰ Há {$days} dias à espera de pagamento — {$order->order_number}", DiscordMessage::WARNING);

            $this->customerFields($message, $order)
                ->field('Total', $this->money($order->total_cents))
                ->field('Canal', Labels::of(Labels::SALES_CHANNELS, $order->sales_channel))
                ->field('Criada', $order->created_at?->format('Y-m-d H:i'));

            $this->send(AlertChannel::ORDERS, $message, $order);
        });
    }

    public function staleProduction(Order $order, int $days): void
    {
        $this->guard(function () use ($order, $days): void {
            $message = DiscordMessage::make("⏰ Há {$days} dias em produção — {$order->order_number}", DiscordMessage::WARNING)
                ->description($this->itemLines($order))
                ->field('Cliente', $order->customer_name)
                ->field('Total', $this->money($order->total_cents));

            $this->send(AlertChannel::STOCK, $message, $order);
        });
    }

    private function customerFields(DiscordMessage $message, Order $order): DiscordMessage
    {
        return $message
            ->field('Cliente', $order->customer_name)
            ->field('Email', $order->email)
            ->field('Telefone', $order->phone)
            ->field('NIF', $order->nif)
            ->field('Morada', $this->address($order), inline: false);
    }

    private function itemLines(Order $order): string
    {
        return $order->items()->get()
            ->map(fn (OrderItem $item): string => "{$item->qty}× {$this->itemName($item)}")
            ->implode("\n");
    }

    private function itemName(OrderItem $item): string
    {
        return trim($item->product_name.($item->variant_label ? " ({$item->variant_label})" : ''));
    }

    private function address(Order $order): ?string
    {
        $address = $order->shipping_address;

        if (! is_array($address) || empty($address['line1'])) {
            return null;
        }

        return collect([
            $address['line1'],
            $address['line2'] ?? null,
            trim(($address['postalCode'] ?? '').' '.($address['city'] ?? '')),
            $address['country'] ?? null,
        ])->filter()->implode(', ');
    }

    private function paymentLabel(Order $order): string
    {
        $status = Labels::of(Labels::PAYMENT_STATUSES, $order->payment_status);
        $method = Labels::of(Labels::PAYMENT_METHODS, $order->payment_method);

        return $method === null ? (string) $status : "{$status} · {$method}";
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ').' €';
    }

    private function send(string $channel, DiscordMessage $message, Order $order): void
    {
        $this->sender->send($channel, $message->url(route('admin.encomendas.show', $order)));
    }

    /**
     * Montar a mensagem le a BD (itens, encomenda). Uma falha aqui nunca
     * sobe para a operacao que a disparou.
     */
    private function guard(callable $build): void
    {
        try {
            $build();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
