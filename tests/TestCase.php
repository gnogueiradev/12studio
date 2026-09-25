<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use phpseclib4\Crypt\RSA;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Os testes nunca dependem de assets compilados: sem isto, qualquer
        // pagina Inertia nova falha com "Unable to locate file in Vite
        // manifest" ate alguem correr `npm run build` (padrao qrcode).
        $this->withoutVite();

        // O Passport assina os tokens com RSA. Em CI nao ha storage/*.key (e
        // nunca deve haver chaves no repositorio), por isso cada processo de
        // testes gera um par proprio, uma vez, e injeta-o pela config — o
        // mesmo caminho que PASSPORT_PRIVATE_KEY usa em producao.
        [$private, $public] = self::passportKeys();
        config(['passport.private_key' => $private, 'passport.public_key' => $public]);
    }

    /**
     * @return array{string, string}
     */
    private static function passportKeys(): array
    {
        static $keys = null;

        if ($keys === null) {
            // phpseclib e nao openssl_pkey_new: no Windows o openssl do PHP
            // falha sem openssl.cnf. E o mesmo que o `passport:keys` usa.
            $key = RSA::createKey(2048);
            $keys = [(string) $key, (string) $key->getPublicKey()];
        }

        return $keys;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
