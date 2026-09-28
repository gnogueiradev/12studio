<?php

namespace App\Jobs;

use App\Alerts\AlertChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Entrega uma mensagem num canal do Discord.
 *
 * Guarda o canal e nao o URL do webhook: o URL e um segredo e nao tem de
 * ficar na tabela jobs, e trocar o webhook no .env vale logo para o que ja
 * esta na fila.
 *
 * Se falhar de vez fica so no log. Um alerta falhado nunca gera outro alerta
 * (o listener de jobs falhados salta esta classe) — senao o Discord em baixo
 * era um ciclo.
 */
class SendDiscordAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $channel,
        public array $payload,
    ) {}

    public function handle(): void
    {
        $url = AlertChannel::webhook($this->channel);

        if ($url === null) {
            return;
        }

        try {
            $response = Http::timeout(10)->post($url, $this->payload);
        } catch (ConnectionException) {
            // Discord inalcancavel: tenta mais tarde. Nunca lanca — numa
            // fila sync a excecao subia ate a operacao que avisou.
            $this->release(30);

            return;
        }

        // Limite do Discord (30/min por webhook): ele diz quanto esperar.
        if ($response->status() === 429) {
            $this->release(max(1, (int) ceil((float) $response->json('retry_after', 5))));

            return;
        }

        if ($response->serverError()) {
            $this->release(30);

            return;
        }

        // 4xx: webhook apagado ou mensagem recusada. Repetir nao muda nada.
        if ($response->failed()) {
            Log::warning('Discord recusou um alerta.', [
                'channel' => $this->channel,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Alerta do Discord perdido.', [
            'channel' => $this->channel,
            'error' => $exception?->getMessage(),
        ]);
    }
}
