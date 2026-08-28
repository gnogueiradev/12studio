<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/**
 * As regras de forca das passwords vinham presas a producao, e fora dela o
 * `null` do AppServiceProvider NAO significava "sem regras": o Laravel cai no
 * Password::min(8) dele (Rules/Password::default). Um staging aceitava oito
 * caracteres sem exigencia nenhuma de complexidade — e um staging com dados a
 * serio e um alvo a serio.
 *
 * Passam a valer em todo o lado menos nos testes, onde as factories e os testes
 * de autenticacao usam passwords simples.
 */
class PasswordStrengthDefaultsTest extends TestCase
{
    private const WEAK = 'segredo1';

    private const STRONG = 'Uma-Password-Mesmo-Forte-2026!';

    private function fails(string $password): bool
    {
        return Validator::make(
            ['password' => $password],
            ['password' => Password::defaults()],
        )->fails();
    }

    public function test_staging_demands_the_same_strength_as_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');

        $this->assertTrue(
            $this->fails(self::WEAK),
            'Oito caracteres sem complexidade passaram fora de producao.',
        );

        $this->assertFalse($this->fails(self::STRONG));
    }

    public function test_production_demands_it_too(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        // A password fraca falha nas regras basicas, e o Rules\Password faz
        // `return` antes de consultar a HaveIBeenPwned — por isso este teste nao
        // depende de haver rede.
        $this->assertTrue($this->fails(self::WEAK));
    }

    /**
     * O uncompromised() faz um pedido HTTP a HaveIBeenPwned. Vale a espera em
     * producao; em dev punha o formulario de password a falhar sem explicacao
     * sempre que nao houvesse rede.
     */
    public function test_only_production_checks_passwords_against_known_breaches(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->assertTrue(Password::defaults()->appliedRules()['uncompromised']);

        $this->app->detectEnvironment(fn (): string => 'staging');
        $this->assertFalse(Password::defaults()->appliedRules()['uncompromised']);
    }

    /**
     * A excepcao deliberada. Endurecer as regras aqui obrigava a reescrever
     * dezenas de testes de autenticacao sem provar nada sobre producao — e um
     * teste que so passa depois de se mudarem os outros todos nao esta a testar
     * o sistema, esta a testar-se a si proprio.
     */
    public function test_the_test_suite_keeps_the_framework_default(): void
    {
        $this->assertSame('testing', $this->app->environment());

        $this->assertFalse($this->fails(self::WEAK));
        $this->assertSame(8, Password::defaults()->appliedRules()['min']);
    }
}
