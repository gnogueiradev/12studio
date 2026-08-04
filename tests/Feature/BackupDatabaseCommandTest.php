<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class BackupDatabaseCommandTest extends TestCase
{
    /**
     * Em testes a BD e :memory:, por isso o comando tem de sair limpo sem
     * criar ficheiros — o caminho VACUUM INTO real so e exercitavel com um
     * ficheiro sqlite (verificado manualmente e em producao).
     */
    public function test_command_skips_in_memory_database(): void
    {
        $this->artisan('db:backup')
            ->expectsOutputToContain('nada para fazer')
            ->assertExitCode(0);
    }

    public function test_backup_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events());

        $backupEvent = $events->first(
            fn ($event): bool => str_contains((string) $event->command, 'db:backup'),
        );

        $this->assertNotNull($backupEvent, 'db:backup nao esta agendado.');
        $this->assertSame('0 4 * * *', $backupEvent->expression);
        $this->assertTrue($backupEvent->withoutOverlapping);
    }
}
