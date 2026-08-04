<?php

namespace Tests\Feature;

use Tests\TestCase;

class QueueRoutingTest extends TestCase
{
    /**
     * Teste-guarda de arquitetura (padrao qrcode): os nomes das filas em
     * config/queue.php (monitored) tem de bater certo com o --queue= do
     * worker do supervisor — senao um job entra numa fila que nenhum worker
     * le e fica pendurado para sempre, em silencio.
     */
    public function test_monitored_queues_match_the_supervisor_worker(): void
    {
        $monitored = config('queue.monitored');

        $this->assertIsArray($monitored);
        $this->assertNotEmpty($monitored, 'config/queue.php precisa da chave "monitored".');

        $confPath = base_path('docker/queue-worker.conf');
        $this->assertFileExists($confPath, 'docker/queue-worker.conf em falta.');

        $conf = (string) file_get_contents($confPath);

        $this->assertSame(
            1,
            preg_match('/--queue=([^\s]+)/', $conf, $matches),
            'docker/queue-worker.conf nao define --queue=.',
        );

        $workerQueues = explode(',', $matches[1]);

        $this->assertSame(
            $monitored,
            $workerQueues,
            'As filas monitorizadas (config/queue.php) divergem do worker (docker/queue-worker.conf).',
        );
    }
}
