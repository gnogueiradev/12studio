<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
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

    /**
     * Primeiro deploy numa maquina nova: o bind mount storage/ chega vazio e o
     * .sqlite so nasce no `migrate --force` (que lhe faz touch). O db:backup
     * corre ANTES disso de proposito, por isso tem de sair limpo — nao ha
     * nada para salvaguardar. Sem isto o SQLiteConnector rebenta a ligacao e
     * parte a cadeia do deploy antes de a BD sequer existir.
     */
    public function test_command_skips_when_the_database_file_does_not_exist(): void
    {
        $missing = storage_path('bd-que-ainda-nao-existe.sqlite');
        $this->assertFileDoesNotExist($missing);

        config(['database.connections.sqlite.database' => $missing]);
        DB::purge('sqlite');

        $this->artisan('db:backup')
            ->expectsOutputToContain('ainda nao existe')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($missing);
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
