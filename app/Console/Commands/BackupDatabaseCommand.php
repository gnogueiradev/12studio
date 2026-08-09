<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackupDatabaseCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:backup {--keep=14 : Numero de backups diarios a manter}';

    /**
     * @var string
     */
    protected $description = 'Copia integra do SQLite via VACUUM INTO para storage/backups, com retencao';

    /**
     * Sem replicacao, o backup diario e a UNICA rede de seguranca da BD de
     * producao (plano, risco 7d). VACUUM INTO produz uma copia consistente
     * mesmo com a app a escrever (compativel com WAL) — nunca copiar o
     * ficheiro .sqlite a mao com a app ligada.
     */
    public function handle(): int
    {
        if (config('database.default') !== 'sqlite') {
            $this->warn('Ligacao default nao e sqlite — nada para fazer.');

            return self::SUCCESS;
        }

        $database = (string) config('database.connections.sqlite.database');

        if ($database === ':memory:') {
            $this->warn('BD em memoria — nada para fazer.');

            return self::SUCCESS;
        }

        // Primeiro deploy numa maquina nova: o bind mount storage/ chega vazio
        // e o .sqlite so nasce no `migrate --force`, que faz touch() ao
        // ficheiro em falta. Este comando corre ANTES do migrate de proposito
        // (o backup e o ponto de restauro pre-migracao), por isso tem de sair
        // limpo quando ainda nao ha BD — nao ha nada para salvaguardar. Sem
        // esta guarda o SQLiteConnector recusa a ligacao, e o `&&` do deploy
        // parte antes de a BD chegar a existir.
        if (! is_file($database) && ! is_file(base_path($database))) {
            $this->warn("Ficheiro da BD ainda nao existe ({$database}) — nada para fazer.");

            return self::SUCCESS;
        }

        $directory = storage_path('backups');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $target = $directory.DIRECTORY_SEPARATOR.'backup-'.now()->format('Ymd-His').'.sqlite';

        // VACUUM INTO exige que o destino nao exista; o timestamp no nome
        // garante isso dentro do mesmo segundo de execucao.
        DB::statement('VACUUM INTO ?', [$target]);

        $this->info("Backup criado: {$target}");

        $this->prune($directory, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    private function prune(string $directory, int $keep): void
    {
        $backups = glob($directory.DIRECTORY_SEPARATOR.'backup-*.sqlite');

        if ($backups === false || count($backups) <= $keep) {
            return;
        }

        // Nomes com timestamp ordenam cronologicamente; apagar os mais antigos.
        sort($backups);
        $stale = array_slice($backups, 0, count($backups) - $keep);

        foreach ($stale as $file) {
            unlink($file);
            $this->line("Backup antigo removido: {$file}");
        }
    }
}
