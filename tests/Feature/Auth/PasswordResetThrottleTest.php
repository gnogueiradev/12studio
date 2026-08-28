<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * O Fortify traz limitadores para o login, o segundo fator e as passkeys, mas
 * regista as duas rotas de reposicao de password so com `guest` — sem tecto
 * nenhum. Um POST em ciclo ao /forgot-password enchia a caixa de correio de
 * quem tem conta e queimava a quota do SMTP.
 *
 * O cadeado do EnsureLoginGate tapa-as hoje, mas isso e ocultacao e nao limite
 * — e cai na Fase 5, quando a loja abrir contas de cliente.
 */
class PasswordResetThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('password-reset|vitima@12studio.test|127.0.0.1');
    }

    public function test_the_sixth_request_in_a_minute_is_refused(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'vitima@12studio.test']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/forgot-password', ['email' => 'vitima@12studio.test'])
                ->assertStatus(302);
        }

        // assertSame no codigo e nao assertStatus(429): quando o assertStatus
        // falha numa resposta 302, o Laravel tenta enriquecer a mensagem com os
        // erros de sessao e rebenta com "Call to a member function all() on
        // array" — quem partisse este teste recebia esse erro em vez de saber
        // que o limite desapareceu.
        $this->assertSame(
            429,
            $this->post('/forgot-password', ['email' => 'vitima@12studio.test'])->getStatusCode(),
            'O sexto pedido de reposicao passou: o ThrottlePasswordReset deixou de estar no grupo `web`.',
        );
    }

    public function test_the_limit_is_per_email_and_not_global(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'vitima@12studio.test']);
        User::factory()->create(['email' => 'outra@12studio.test']);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->post('/forgot-password', ['email' => 'vitima@12studio.test']);
        }

        // Quem soubesse um endereco nao pode trancar a loja inteira fora da
        // reposicao de password.
        $this->post('/forgot-password', ['email' => 'outra@12studio.test'])
            ->assertStatus(302);
    }

    public function test_the_confirmation_route_is_limited_too(): void
    {
        // Sem tecto aqui, o /reset-password era um oraculo para adivinhar
        // tokens a forca bruta.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/reset-password', [
                'token' => 'token-invalido',
                'email' => 'vitima@12studio.test',
                'password' => 'seja-o-que-for-comprido',
                'password_confirmation' => 'seja-o-que-for-comprido',
            ]);
        }

        $this->assertSame(
            429,
            $this->post('/reset-password', [
                'token' => 'token-invalido',
                'email' => 'vitima@12studio.test',
                'password' => 'seja-o-que-for-comprido',
                'password_confirmation' => 'seja-o-que-for-comprido',
            ])->getStatusCode(),
            'A confirmacao de reposicao ficou sem tecto: da para adivinhar tokens a forca bruta.',
        );
    }

    public function test_other_routes_are_untouched(): void
    {
        // O middleware esta no grupo `web`, logo ve TODOS os pedidos. Tem de
        // sair da frente em tudo o que nao seja reposicao de password.
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->get('/')->assertOk();
        }
    }
}
