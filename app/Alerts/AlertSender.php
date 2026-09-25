<?php

namespace App\Alerts;

use App\Jobs\SendDiscordAlert;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A unica porta para o Discord.
 *
 * Nunca rebenta: montar ou despachar um alerta corre dentro de try/catch,
 * porque uma encomenda grava-se mesmo que o aviso falhe.
 */
class AlertSender
{
    /**
     * Pela fila, depois do commit: um rollback nao avisa de encomendas que
     * nunca existiram.
     */
    public function send(string $channel, DiscordMessage $message): void
    {
        try {
            if (AlertChannel::webhook($channel) === null) {
                return;
            }

            SendDiscordAlert::dispatch($channel, $message->toPayload())->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Sem fila, para quando a fila pode ser o problema (erros 500: se a BD
     * caiu, a tabela jobs caiu com ela). Nunca lanca e nunca reporta — e
     * chamado de dentro do report(), e reportar aqui era um ciclo.
     */
    public function sendNow(string $channel, DiscordMessage $message): bool
    {
        $url = AlertChannel::webhook($channel);

        if ($url === null) {
            return false;
        }

        try {
            return Http::timeout(3)->post($url, $message->toPayload())->successful();
        } catch (Throwable $exception) {
            Log::warning('Alerta do Discord nao saiu.', ['channel' => $channel, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * Quem fez: "Ana" ou "Ana via Claude", quando o pedido veio do MCP.
     */
    public function actor(?User $user): string
    {
        $name = $user === null ? 'Sistema' : $user->name;

        return request()->is('mcp', 'mcp/*') ? "{$name} via Claude" : $name;
    }
}
