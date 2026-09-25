<?php

namespace Tests\Feature\Alerts;

use App\Alerts\AlertChannel;
use App\Alerts\SystemAlerts;
use App\Jobs\SendDiscordAlert;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SystemAlertsTest extends TestCase
{
    use FakesDiscord;
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDiscord();

        // storage/ proprio por teste: o batimento e os backups sao ficheiros.
        $this->storage = sys_get_temp_dir().'/12studio-alerts-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/framework');
        File::ensureDirectoryExists($this->storage.'/backups');
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    private function boom(): RuntimeException
    {
        return new RuntimeException('A impressora pegou fogo');
    }

    public function test_the_same_error_warns_once_per_window_and_then_counts(): void
    {
        foreach (range(1, 5) as $ignored) {
            report($this->boom());
        }

        $this->assertSame(['💥 Erro — RuntimeException'], $this->alertTitles(AlertChannel::SYSTEM));

        $this->travel(11)->minutes();
        report($this->boom());

        $embed = $this->alertEmbed(AlertChannel::SYSTEM, 'Erro — RuntimeException');
        $this->assertCount(2, $this->alertTitles(AlertChannel::SYSTEM));
        $this->assertStringContainsString('A impressora pegou fogo', $this->embedText($embed));
    }

    public function test_the_repeat_count_arrives_with_the_next_warning(): void
    {
        foreach (range(1, 4) as $ignored) {
            report($this->boom());
        }

        $this->travel(11)->minutes();
        report($this->boom());

        $last = Http::recorded()->last()[0]->data()['embeds'][0];
        $this->assertStringContainsString('Repetições desde o último aviso: 3', $this->embedText($last));
    }

    public function test_a_failed_job_is_reported(): void
    {
        app(SystemAlerts::class)->jobFailed(new JobFailed('database', $this->job('App\\Mail\\OrderShippedMail'), new RuntimeException('SMTP recusou')));

        $text = $this->embedText($this->alertEmbed(AlertChannel::SYSTEM, 'Tarefa da fila falhou — OrderShippedMail'));
        $this->assertStringContainsString('SMTP recusou', $text);
    }

    public function test_a_lost_discord_alert_does_not_alert_about_itself(): void
    {
        app(SystemAlerts::class)->jobFailed(new JobFailed('database', $this->job(SendDiscordAlert::class), new RuntimeException('Discord em baixo')));

        $this->assertSame([], $this->alertTitles(AlertChannel::SYSTEM));
    }

    public function test_a_backup_that_produced_a_file_reports_its_size(): void
    {
        file_put_contents($this->storage.'/backups/backup-20260925-040000.sqlite', str_repeat('x', 2048));

        app(SystemAlerts::class)->backupFinished();

        $this->assertStringContainsString('2 KB', $this->embedText($this->alertEmbed(AlertChannel::SYSTEM, 'Backup feito')));
    }

    public function test_a_backup_that_produced_nothing_is_a_failure(): void
    {
        app(SystemAlerts::class)->backupFinished();

        $this->assertAlerted(AlertChannel::SYSTEM, 'Backup falhou');
    }

    public function test_a_stopped_scheduler_is_noticed_by_the_health_check(): void
    {
        touch($this->storage.'/framework/scheduler-heartbeat', now()->subMinutes(15)->getTimestamp());

        $this->get('/up')->assertOk();
        $this->get('/up')->assertOk();

        $this->assertSame(['⏱️ Scheduler parado há 15 min'], $this->alertTitles(AlertChannel::SYSTEM));

        // Voltou: o proximo batimento avisa.
        app(SystemAlerts::class)->heartbeat();

        $this->assertAlerted(AlertChannel::SYSTEM, 'Scheduler voltou a bater');
    }

    public function test_a_beating_scheduler_is_quiet(): void
    {
        app(SystemAlerts::class)->heartbeat();

        $this->get('/up')->assertOk();

        $this->assertSame([], $this->alertTitles(AlertChannel::SYSTEM));
    }

    public function test_the_alert_jobs_are_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events());

        $expression = fn (string $needle): ?string => $events
            ->first(fn ($event): bool => str_contains((string) ($event->command ?? $event->description), $needle))
            ?->expression;

        $this->assertSame('0 * * * *', $expression('alerts:check'));
        $this->assertSame('*/15 * * * *', $expression('alerts:mcp-reads'));
        $this->assertSame('* * * * *', $expression('alerts:heartbeat'));
    }

    private function job(string $name): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn($name);
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('attempts')->andReturn(3);

        return $job;
    }
}
