<?php

namespace App\Alerts;

use App\Jobs\SendDiscordAlert;
use App\Models\AlertState;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * #sistema: o que parte e o que para.
 *
 * Os avisos daqui saem pelo sendNow (sem fila) sempre que a fila ou a BD
 * podem ser a causa do problema, e nenhum metodo lanca excecoes nem chama
 * report(): varios correm DENTRO do report() do Laravel, e reportar aqui era
 * um ciclo.
 */
class SystemAlerts
{
    /** Onde o scheduler bate a cada minuto (storage/ e persistente). */
    private const HEARTBEAT_FILE = 'framework/scheduler-heartbeat';

    public function __construct(
        private AlertSender $sender,
    ) {}

    /**
     * Excecao reportada. A mesma (classe + ficheiro + linha) avisa uma vez
     * por janela; as repeticoes contam, e o aviso seguinte diz quantas houve.
     */
    public function exception(Throwable $exception): void
    {
        try {
            if (AlertChannel::webhook(AlertChannel::SYSTEM) === null) {
                return;
            }

            $fingerprint = sha1($exception::class.'|'.$exception->getFile().'|'.$exception->getLine());
            $window = now()->addMinutes((int) config('alerts.error_window_minutes', 10));

            if (! Cache::add("alerts:error:{$fingerprint}", true, $window)) {
                Cache::increment("alerts:error-count:{$fingerprint}");

                return;
            }

            $repeated = (int) Cache::pull("alerts:error-count:{$fingerprint}", 0);

            $this->sender->sendNow(AlertChannel::SYSTEM, DiscordMessage::make('💥 Erro — '.class_basename($exception), DiscordMessage::DANGER)
                ->description(Str::limit($exception->getMessage(), 1500))
                ->field('Onde', Str::after($exception->getFile(), base_path().DIRECTORY_SEPARATOR).':'.$exception->getLine(), inline: false)
                ->field('Pedido', $this->requestLabel(), inline: false)
                ->field('Conta', Auth::hasUser() ? Auth::user()?->getAuthIdentifier().' · '.(Auth::user()->name ?? '') : null)
                ->field('Repetições desde o último aviso', $repeated > 0 ? (string) $repeated : null));
        } catch (Throwable) {
            // Nada: estamos dentro do report().
        }
    }

    /**
     * Job que esgotou as tentativas. Os alertas do proprio Discord ficam de
     * fora — um Discord em baixo nao pode avisar que esta em baixo.
     */
    public function jobFailed(JobFailed $event): void
    {
        try {
            $name = $event->job->resolveName();

            if ($name === SendDiscordAlert::class) {
                return;
            }

            $this->sender->sendNow(AlertChannel::SYSTEM, DiscordMessage::make('📭 Tarefa da fila falhou — '.class_basename($name), DiscordMessage::DANGER)
                ->description(Str::limit($event->exception->getMessage(), 1500))
                ->field('Tarefa', $name, inline: false)
                ->field('Fila', $event->job->getQueue())
                ->field('Tentativas', (string) $event->job->attempts())
                ->field('O que fazer', 'Ver em failed_jobs; `php artisan queue:retry all` volta a tentar.', inline: false));
        } catch (Throwable) {
        }
    }

    /**
     * Depois do backup das 04:00. Sucesso do comando nao chega: confirma que
     * nasceu mesmo um ficheiro (o comando sai com sucesso quando nao ha BD).
     */
    public function backupFinished(): void
    {
        try {
            $files = glob(storage_path('backups').DIRECTORY_SEPARATOR.'backup-*.sqlite') ?: [];
            sort($files);
            $latest = end($files);

            clearstatcache();

            if ($latest === false || filemtime($latest) < now()->subMinutes(30)->getTimestamp()) {
                $this->backupFailed('O comando terminou mas não criou nenhum ficheiro novo.');

                return;
            }

            $this->sender->send(AlertChannel::SYSTEM, DiscordMessage::make('💾 Backup feito', DiscordMessage::SUCCESS)
                ->field('Ficheiro', basename($latest))
                ->field('Tamanho', $this->size((int) filesize($latest)))
                ->field('Backups guardados', (string) count($files)));
        } catch (Throwable) {
        }
    }

    public function backupFailed(?string $reason = null): void
    {
        $this->sender->sendNow(AlertChannel::SYSTEM, DiscordMessage::make('🚨 Backup falhou', DiscordMessage::DANGER)
            ->description($reason ?? 'O comando db:backup terminou com erro.')
            ->field('Consequência', 'Sem backup de hoje: o último bom é o de ontem, se existir.', inline: false));
    }

    public function scheduledTaskFailed(string $task, ?string $output): void
    {
        $this->sender->sendNow(AlertChannel::SYSTEM, DiscordMessage::make("⚠️ Tarefa agendada falhou — {$task}", DiscordMessage::DANGER)
            ->description($output === null || trim($output) === '' ? null : '```'."\n".Str::limit(trim($output), 1500)."\n".'```'));
    }

    /**
     * O scheduler bate a cada minuto. Se o ultimo aviso foi "parado", este
     * batimento avisa que voltou.
     */
    public function heartbeat(): void
    {
        try {
            @touch(storage_path(self::HEARTBEAT_FILE));

            if (AlertState::query()->whereKey('scheduler-stale')->exists()) {
                AlertState::release('scheduler-stale');
                Cache::forget('alerts:scheduler-stale');

                $this->sender->send(AlertChannel::SYSTEM, DiscordMessage::make('✅ Scheduler voltou a bater', DiscordMessage::SUCCESS));
            }
        } catch (Throwable) {
        }
    }

    /**
     * Chamado pelo /up (o Docker bate-lhe a cada 30 s). Nunca faz o /up
     * falhar: um scheduler parado nao e razao para o Docker matar o site.
     */
    public function checkHeartbeat(): void
    {
        try {
            if (AlertChannel::webhook(AlertChannel::SYSTEM) === null) {
                return;
            }

            $file = storage_path(self::HEARTBEAT_FILE);
            clearstatcache();

            // Primeira vez (servidor novo): comeca a contar daqui.
            if (! is_file($file)) {
                @touch($file);

                return;
            }

            $minutes = (int) floor((now()->getTimestamp() - (int) filemtime($file)) / 60);

            if ($minutes <= (int) config('alerts.scheduler_stale_minutes', 10)) {
                return;
            }

            // No maximo um aviso por hora enquanto estiver parado.
            if (! Cache::add('alerts:scheduler-stale', true, now()->addHour())) {
                return;
            }

            AlertState::claim('scheduler-stale');

            $this->sender->sendNow(AlertChannel::SYSTEM, DiscordMessage::make("⏱️ Scheduler parado há {$minutes} min", DiscordMessage::DANGER)
                ->description('Sem ele não há backup, limpezas, verificador de encomendas paradas nem resumo do MCP.')
                ->field('O que fazer', 'Ver o processo `scheduler` no supervisor do container (`supervisorctl status`).', inline: false));
        } catch (Throwable) {
        }
    }

    private function requestLabel(): string
    {
        if (app()->runningInConsole()) {
            return 'Consola / fila';
        }

        $request = request();

        return $request->method().' '.Str::limit($request->getRequestUri(), 200);
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? number_format($bytes / 1_048_576, 1, ',', ' ').' MB'
            : number_format($bytes / 1024, 0, ',', ' ').' KB';
    }
}
