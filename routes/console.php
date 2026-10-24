<?php

use App\Alerts\SystemAlerts;
use App\Models\McpActivity;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Stringable;

// Scheduler corrido em producao pelo supervisor (`php artisan schedule:work`,
// docker/scheduler.conf) — nao por cron do sistema. Tudo withoutOverlapping
// (padrao qrcode).
//
// Cada tarefa que falhe avisa o #sistema do Discord com o output.
$alertOnFailure = fn (Event $event, string $task): Event => $event
    ->storeOutput()
    ->onFailureWithOutput(fn (Stringable $output) => app(SystemAlerts::class)->scheduledTaskFailed($task, (string) $output));

// Backup diario do SQLite as 04:00 — sem replicacao, e a unica rede de
// seguranca da BD (plano, risco 7d). A copia para fora da maquina e feita
// pelo dono a partir de storage/backups (bind-mounted no host).
//
// O aviso e deste agendamento e nao do comando: o db:backup tambem corre em
// cada deploy, e la "ainda nao ha BD" e normal. Aqui, as 04:00, nao e.
Schedule::command('db:backup')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->storeOutput()
    ->onSuccess(fn () => app(SystemAlerts::class)->backupFinished())
    ->onFailureWithOutput(fn (Stringable $output) => app(SystemAlerts::class)->backupFailed((string) $output ?: null));

// Tokens do Passport expirados ou revogados: sem isto a tabela so cresce.
$alertOnFailure(Schedule::command('passport:purge')
    ->dailyAt('04:30')
    ->withoutOverlapping(), 'passport:purge');

// Clientes OAuth que se registaram (registo dinamico, publico) e nunca foram
// autorizados em 24 h.
$alertOnFailure(Schedule::command('mcp:prune-clients')
    ->dailyAt('04:40')
    ->withoutOverlapping(), 'mcp:prune-clients');

// Rasto do MCP com mais de 12 meses (McpActivity::prunable).
$alertOnFailure(Schedule::command('model:prune', ['--model' => [McpActivity::class]])
    ->dailyAt('04:45')
    ->withoutOverlapping(), 'model:prune');

// Alertas do Discord: o que NAO esta a acontecer (encomendas paradas, bobines,
// chaves a expirar) e o resumo das leituras do Claude.
$alertOnFailure(Schedule::command('alerts:check')
    ->hourly()
    ->withoutOverlapping(), 'alerts:check');

$alertOnFailure(Schedule::command('alerts:mcp-reads')
    ->everyFifteenMinutes()
    ->withoutOverlapping(), 'alerts:mcp-reads');

// Etsy: os anuncios de acordo com os produtos. O stock avisa sozinho quando
// mexe; isto apanha o que nao avisa (precos, nomes, um produto arquivado). Com
// a integracao desligada o comando sai logo.
$alertOnFailure(Schedule::command('etsy:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping(), 'etsy:sync');

// De quatro em quatro horas, e nao so de noite: os termos da API da Etsy nao
// deixam mostrar o estado de um anuncio com mais de seis horas.
$alertOnFailure(Schedule::command('etsy:sync --full')
    ->everyFourHours(30)
    ->withoutOverlapping(), 'etsy:sync --full');

// As encomendas da Etsy: entram sozinhas, descem o stock e aparecem no
// quadro de producao. E esta a janela em que uma peca pode ser vendida nos
// dois lados; o envio de stock para a Etsy importa primeiro, por isso mesmo.
$alertOnFailure(Schedule::command('etsy:import-orders')
    ->everyFiveMinutes()
    ->withoutOverlapping(), 'etsy:import-orders');

// As versoes reduzidas das fotografias que ainda nao as tem: as carregadas
// antes de elas existirem, e alguma cujo job tenha falhado. Sem nada em
// falta, nao poe nada na fila.
$alertOnFailure(Schedule::command('images:variants')
    ->dailyAt('04:45')
    ->withoutOverlapping(), 'images:variants');

// Batimento: o /up (healthcheck do Docker) avisa se isto parar.
Schedule::call(fn () => app(SystemAlerts::class)->heartbeat())
    ->everyMinute()
    ->name('alerts:heartbeat')
    ->withoutOverlapping();

// Fase 3 acrescenta aqui o sweep de reservas de stock expiradas
// (a cada 15 minutos) — rede de seguranca para eventos Stripe perdidos.
