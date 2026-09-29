<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Passport\Passport;
use Tests\TestCase;

class McpInstallCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_personal_access_client_once(): void
    {
        $this->artisan('mcp:install')->assertSuccessful();
        $this->artisan('mcp:install')->assertSuccessful();

        $this->assertSame(1, Passport::client()->newQuery()->count());
    }

    /**
     * O league/oauth2-server so aceita 400/440/600/640/660 — tambem na chave
     * PUBLICA, que o auth:api le a cada pedido ao /mcp. Com 644 dispara um
     * E_USER_NOTICE, o Laravel faz dele excecao e o /mcp responde 500.
     */
    public function test_existing_keys_get_permissions_the_oauth_server_accepts(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('O Windows nao tem permissoes Unix; o Jenkins corre isto em Linux.');
        }

        $dir = storage_path('framework/testing/passport-keys');
        File::ensureDirectoryExists($dir);
        Passport::loadKeysFrom($dir);

        try {
            $this->artisan('passport:keys', ['--force' => true])->assertSuccessful();
            chmod($dir.'/oauth-private.key', 0644);
            chmod($dir.'/oauth-public.key', 0644);

            $this->artisan('mcp:install')->assertSuccessful();

            clearstatcache();
            $this->assertSame('600', decoct(fileperms($dir.'/oauth-private.key') & 0777));
            $this->assertSame('640', decoct(fileperms($dir.'/oauth-public.key') & 0777));
        } finally {
            Passport::$keyPath = null;
            File::deleteDirectory($dir);
        }
    }
}
