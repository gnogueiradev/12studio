<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * Prepara o MCP num servidor novo. Pode correr-se as vezes que se quiser:
 * so faz o que falta.
 *
 *   1. Chaves RSA do Passport em storage/ (o bind-mount persistente em
 *      producao, com a BD e os uploads). Nunca na imagem nem no repo.
 *   2. O cliente de "chaves pessoais" do Passport — uma linha em
 *      oauth_clients sem a qual a pagina de chaves nao consegue criar tokens.
 *
 * E um comando manual, e nao um passo do deploy: escreve na BD de producao, e
 * essas escritas fazem-se a vista.
 */
class McpInstallCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mcp:install';

    /**
     * @var string
     */
    protected $description = 'Gera as chaves do Passport e o cliente de chaves pessoais, se faltarem';

    public function handle(ClientRepository $clients): int
    {
        $this->ensureKeys();
        $this->ensurePersonalAccessClient($clients);

        return self::SUCCESS;
    }

    private function ensureKeys(): void
    {
        if (config('passport.private_key') || file_exists(Passport::keyPath('oauth-private.key'))) {
            $this->components->info('Chaves do Passport: já existem.');

            return;
        }

        $this->call('passport:keys');

        foreach (['oauth-private.key', 'oauth-public.key'] as $file) {
            // So o dono do processo le a chave privada.
            @chmod(Passport::keyPath($file), $file === 'oauth-private.key' ? 0600 : 0644);
        }

        $this->components->info('Chaves do Passport: criadas em storage/.');
    }

    private function ensurePersonalAccessClient(ClientRepository $clients): void
    {
        $exists = Passport::client()->newQuery()
            ->where('revoked', false)
            ->get()
            ->contains(fn (Client $client): bool => $client->hasGrantType('personal_access'));

        if ($exists) {
            $this->components->info('Cliente de chaves pessoais: já existe.');

            return;
        }

        $clients->createPersonalAccessGrantClient('Chaves de API do 12studio', 'users');

        $this->components->info('Cliente de chaves pessoais: criado.');
    }
}
