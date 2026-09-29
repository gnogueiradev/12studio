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
 * Corre em cada deploy (Jenkinsfile, a seguir ao migrate) — por isso tem de
 * continuar idempotente. Nunca troca chaves que ja existam: uma chave privada
 * nova invalidava todas as chaves de API e ligacoes OAuth emitidas.
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
        } else {
            $this->call('passport:keys');
            $this->components->info('Chaves do Passport: criadas em storage/.');
        }

        $this->fixKeyPermissions();
    }

    /**
     * Tambem com as chaves ja existentes: quem as criou com 644 fica corrigido
     * ao correr isto outra vez. O league/oauth2-server so aceita 400/440/600/
     * 640/660 — e verifica a PUBLICA a cada pedido ao /mcp. Com 644 dispara um
     * E_USER_NOTICE, o Laravel faz dele excecao e o /mcp responde 500.
     */
    private function fixKeyPermissions(): void
    {
        foreach (['oauth-private.key' => 0600, 'oauth-public.key' => 0640] as $file => $mode) {
            $path = Passport::keyPath($file);

            if (file_exists($path)) {
                @chmod($path, $mode);
            }
        }
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
