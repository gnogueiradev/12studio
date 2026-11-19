<?php

namespace App\Jobs;

use App\Stripe\StripeClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fecha uma sessao do Stripe que ja nao devia cobrar. So toca na sessao que lhe
 * pediram: se entretanto nasceu outra para a encomenda, essa fica.
 */
class ExpireStripeSession implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public function __construct(
        public int $orderId,
        public string $sessionId,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(StripeClient $stripe): void
    {
        $session = $stripe->get("checkout/sessions/{$this->sessionId}");

        if (($session['status'] ?? null) === 'open') {
            $stripe->post("checkout/sessions/{$this->sessionId}/expire");
        }

        Log::channel('api')->info('Sessao do Stripe desatualizada tratada.', [
            'order_id' => $this->orderId,
            'session_id' => $this->sessionId,
            'status' => $session['status'] ?? null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('api')->warning('Sessao do Stripe desatualizada continua aberta.', [
            'order_id' => $this->orderId,
            'session_id' => $this->sessionId,
            'error' => $exception === null ? null : $exception::class,
        ]);
    }
}
