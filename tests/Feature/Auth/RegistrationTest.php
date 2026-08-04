<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class RegistrationTest extends TestCase
{
    /**
     * O registo publico esta DESATIVADO ate a Fase 5 (contas de cliente):
     * so o admin seeded pode entrar. Este teste rebenta se alguem reativar
     * Features::registration() sem querer.
     */
    public function test_registration_routes_do_not_exist(): void
    {
        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Intruso',
            'email' => 'intruso@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
    }
}
