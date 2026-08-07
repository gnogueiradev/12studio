<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureLoginGate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginGateTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-de-teste';

    /**
     * O middleware le a config em runtime, por isso da para ligar o cadeado
     * depois de as rotas do Fortify ja estarem registadas.
     */
    private function lockGate(): void
    {
        config(['access.login_secret' => self::SECRET]);
    }

    public function test_gate_is_off_when_no_secret_is_configured(): void
    {
        config(['access.login_secret' => null]);

        $this->get('/login')->assertOk();
    }

    public function test_login_is_hidden_without_the_cookie(): void
    {
        $this->lockGate();

        $this->get('/login')->assertNotFound();
    }

    public function test_other_fortify_routes_are_hidden_too(): void
    {
        $this->lockGate();

        $this->get('/forgot-password')->assertNotFound();
    }

    public function test_the_secret_url_opens_the_gate(): void
    {
        $this->lockGate();

        $this->get('/acesso/'.self::SECRET)
            ->assertRedirect(route('login'))
            ->assertCookie(EnsureLoginGate::COOKIE, EnsureLoginGate::token(self::SECRET));
    }

    public function test_a_wrong_secret_looks_like_any_other_404(): void
    {
        $this->lockGate();

        $this->get('/acesso/errado')->assertNotFound();
    }

    public function test_login_is_reachable_with_the_cookie(): void
    {
        $this->lockGate();

        // withCookie e nao withUnencryptedCookie: o cookie nao esta na lista de
        // excecoes do encryptCookies em bootstrap/app.php, por isso chega
        // encriptado tal como chegaria de um browser real.
        $this->withCookie(
            EnsureLoginGate::COOKIE,
            EnsureLoginGate::token(self::SECRET),
        )->get('/login')->assertOk();
    }

    public function test_authenticated_users_are_never_locked_out(): void
    {
        $this->lockGate();

        // O cookie dura dias, a sessao dura horas: sem esta excecao um admin
        // logado com o cookie expirado perdia o /logout.
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect();
    }
}
