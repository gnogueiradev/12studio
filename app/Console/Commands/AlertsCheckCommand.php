<?php

namespace App\Console\Commands;

use App\Alerts\OrderAlerts;
use App\Alerts\SecurityAlerts;
use App\Alerts\StockAlerts;
use App\Models\AlertState;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Passport\Token;

/**
 * O que NAO esta a acontecer: encomendas paradas, bobines a acabar, chaves a
 * expirar. Corre de hora a hora.
 *
 * Cada problema avisa uma vez e relembra a cada config('alerts.reminder_hours')
 * enquanto durar (alert_states). Quando passa, a marca apaga-se: se voltar,
 * volta a avisar logo.
 */
class AlertsCheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'alerts:check';

    /**
     * @var string
     */
    protected $description = 'Avisa no Discord de encomendas paradas, bobines a acabar e chaves a expirar';

    public function handle(OrderAlerts $orders, StockAlerts $stock, SecurityAlerts $security): int
    {
        $this->stalePayments($orders);
        $this->staleProduction($orders);
        $this->lowMaterials($stock);
        $this->expiringKeys($security);

        return self::SUCCESS;
    }

    private function stalePayments(OrderAlerts $alerts): void
    {
        $days = (int) config('alerts.stale_payment_days', 3);

        $orders = Order::query()
            ->where('status', 'pending_payment')
            ->where('created_at', '<=', now()->subDays($days))
            ->get();

        foreach ($orders as $order) {
            if (AlertState::claim("stale-payment:{$order->id}")) {
                $alerts->stalePayment($order, $this->daysSince($order->created_at));
            }
        }

        $this->forgetResolved('stale-payment:', $orders->pluck('id')->all());
    }

    /**
     * Contado desde que ENTROU em producao (historico), nao desde que a
     * encomenda nasceu: uma encomenda que esperou uma semana pelo pagamento
     * nao esta parada na impressora no primeiro dia.
     */
    private function staleProduction(OrderAlerts $alerts): void
    {
        $days = (int) config('alerts.stale_production_days', 3);
        $stale = [];

        $orders = Order::query()->where('status', 'in_production')->get();

        foreach ($orders as $order) {
            $enteredAt = OrderStatusHistory::query()
                ->where('order_id', $order->id)
                ->where('to_status', 'in_production')
                ->max('created_at');

            $since = $enteredAt === null ? $order->created_at : Carbon::parse((string) $enteredAt);

            if ($since === null || $since->gt(now()->subDays($days))) {
                continue;
            }

            $stale[] = $order->id;

            if (AlertState::claim("stale-production:{$order->id}")) {
                $alerts->staleProduction($order, $this->daysSince($since));
            }
        }

        $this->forgetResolved('stale-production:', $stale);
    }

    private function lowMaterials(StockAlerts $alerts): void
    {
        $low = Material::query()
            ->where('active', true)
            ->get()
            ->filter(fn (Material $material): bool => $material->isLowStock());

        foreach ($low as $material) {
            if (AlertState::claim("material-low:{$material->id}")) {
                $alerts->materialLow($material);
            }
        }

        $this->forgetResolved('material-low:', $low->pluck('id')->all());
    }

    /**
     * Chaves pessoais (as que tem nome) com validade a acabar. As sem validade
     * nunca entram; o OAuth renova-se sozinho.
     */
    private function expiringKeys(SecurityAlerts $alerts): void
    {
        $tokens = Token::query()
            ->where('revoked', false)
            ->whereNotNull('name')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays((int) config('alerts.key_expiry_warning_days', 3))])
            ->get();

        foreach ($tokens as $token) {
            $user = User::query()->find($token->user_id);

            if ($user instanceof User && AlertState::claim("key-expiring:{$token->id}")) {
                $alerts->keyExpiring($token, $user);
            }
        }

        $this->forgetResolved('key-expiring:', $tokens->pluck('id')->all());
    }

    /**
     * @param  array<int, int|string>  $stillOpen
     */
    private function forgetResolved(string $prefix, array $stillOpen): void
    {
        $open = array_map(fn (int|string $id): string => $prefix.$id, $stillOpen);

        foreach (AlertState::keysWithPrefix($prefix) as $key) {
            if (! in_array($key, $open, true)) {
                AlertState::release($key);
            }
        }
    }

    private function daysSince(?DateTimeInterface $date): int
    {
        return $date === null ? 0 : (int) floor(Carbon::parse($date)->diffInDays(now()));
    }
}
