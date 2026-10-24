<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O registo publico esta DESATIVADO ate a Fase 5 (contas de cliente):
     * so o admin seeded pode entrar. Este teste rebenta se alguem reativar
     * Features::registration() sem querer.
     */
    public function test_registration_routes_do_not_exist(): void
    {
        $this->get('/register')->assertNotFound();

        // 405 e nao 404: a rota das landing pages da montra ('/{slug}', so
        // GET) apanha o endereco, e o router responde que o metodo nao serve.
        // O que importa e o mesmo — nao ha registo.
        $status = $this->post('/register', [
            'name' => 'Intruso',
            'email' => 'intruso@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->status();

        $this->assertContains($status, [404, 405]);
        $this->assertDatabaseMissing('users', ['email' => 'intruso@example.com']);
    }
}
