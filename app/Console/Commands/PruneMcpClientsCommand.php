<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * Apaga os clientes OAuth que se registaram sozinhos (registo dinamico do
 * MCP) e nunca foram autorizados por ninguem em 24 horas.
 *
 * O /oauth/register e publico por natureza — e assim que o claude.ai se
 * apresenta —, e cada tentativa deixa uma linha em oauth_clients. Sem esta
 * limpeza a tabela so crescia com lixo. Um cliente que chegou a ter um token
 * (mesmo ja revogado) fica: e historial, e o rasto do MCP aponta para ele.
 */
class PruneMcpClientsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mcp:prune-clients {--hours=24 : Idade minima de um cliente nunca usado}';

    /**
     * @var string
     */
    protected $description = 'Apaga clientes OAuth registados e nunca autorizados';

    public function handle(): int
    {
        $cutoff = now()->subHours(max(1, (int) $this->option('hours')));

        $deleted = 0;

        Passport::client()->newQuery()
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('tokens')
            ->get()
            ->filter(fn (Client $client): bool => $client->hasGrantType('authorization_code')
                && ! $client->hasGrantType('personal_access'))
            ->each(function (Client $client) use (&$deleted): void {
                $client->delete();
                $deleted++;
            });

        $this->components->info("Clientes OAuth apagados: {$deleted}.");

        return self::SUCCESS;
    }
}
