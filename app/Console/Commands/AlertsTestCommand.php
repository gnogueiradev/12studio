<?php

namespace App\Console\Commands;

use App\Alerts\AlertChannel;
use App\Alerts\AlertSender;
use App\Alerts\DiscordMessage;
use Illuminate\Console\Command;

/**
 * Manda uma mensagem de teste a cada canal do Discord, sem fila, e diz qual
 * respondeu. Para confirmar os webhooks do .env depois de os meter.
 */
class AlertsTestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'alerts:test';

    /**
     * @var string
     */
    protected $description = 'Envia uma mensagem de teste para cada canal do Discord';

    public function handle(AlertSender $sender): int
    {
        $failed = false;

        foreach (AlertChannel::ALL as $channel) {
            if (AlertChannel::webhook($channel) === null) {
                $this->components->warn("#{$channel}: sem webhook (DISCORD_WEBHOOK_".strtoupper($channel).' vazio) — desligado.');

                continue;
            }

            $ok = $sender->sendNow($channel, DiscordMessage::make('🔔 Teste de ligação', DiscordMessage::SUCCESS)
                ->description("Se estás a ler isto em #{$channel}, os alertas deste canal estão ligados."));

            $ok
                ? $this->components->info("#{$channel}: enviado.")
                : $this->components->error("#{$channel}: o Discord não aceitou — confirma o URL do webhook.");

            $failed = $failed || ! $ok;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
