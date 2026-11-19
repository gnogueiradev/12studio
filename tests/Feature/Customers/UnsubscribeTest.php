<?php

namespace Tests\Feature\Customers;

use App\Customers\RepeatOffers;
use App\Models\User;
use App\Services\SettingService;
use App\Services\StoreSettings;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Deixar de receber ofertas pelo link do email. O POST de um clique (RFC 8058)
 * chega do fornecedor de email, sem sessao nem token de CSRF; o GET so mostra
 * o botao, porque os leitores de email abrem os links sozinhos. O que guarda a
 * rota e a assinatura, e funciona com a loja desligada: a lei obriga a que
 * cada email deixe recusar.
 */
class UnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    private function path(User $user): string
    {
        $url = app(RepeatOffers::class)->unsubscribeUrl($user);

        return (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    /**
     * Nos testes o Laravel salta o CSRF; aqui volta a estar ligado, como em
     * producao.
     */
    private function enforceCsrf(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    public function test_the_one_click_post_opts_out_without_csrf_or_session(): void
    {
        $this->enforceCsrf();
        $user = User::factory()->create();

        // Prova de que o CSRF esta mesmo ligado neste teste.
        $this->post('/conta/sair')->assertStatus(419);

        $this->call('POST', $this->path($user), ['List-Unsubscribe' => 'One-Click'])
            ->assertOk();

        $this->assertNotNull($user->fresh()?->marketing_opt_out_at);

        // Repetir nao muda a data da recusa.
        $first = $user->fresh()?->marketing_opt_out_at;
        $this->travel(1)->hours();
        $this->call('POST', $this->path($user), ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->assertEquals($first, $user->fresh()?->marketing_opt_out_at);
    }

    public function test_the_get_shows_a_button_and_changes_nothing(): void
    {
        $user = User::factory()->create();

        $this->get($this->path($user))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/offers-unsubscribe')
                ->where('action', $this->path($user))
                ->where('done', false));

        $this->assertNull($user->fresh()?->marketing_opt_out_at);

        // O botao da pagina faz o mesmo POST e mostra que ficou feito.
        $this->post($this->path($user))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/offers-unsubscribe')
                ->where('done', true));

        $this->assertNotNull($user->fresh()?->marketing_opt_out_at);
    }

    public function test_unsigned_or_tampered_links_are_403(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $path = $this->path($user);

        $this->get("/ofertas/sair/{$user->id}")->assertForbidden();
        $this->post("/ofertas/sair/{$user->id}")->assertForbidden();

        // A assinatura de um serve para outro.
        $this->post(str_replace("/ofertas/sair/{$user->id}", "/ofertas/sair/{$other->id}", $path))->assertForbidden();

        // Assinatura mexida.
        $this->post(substr($path, 0, -1).(str_ends_with($path, '0') ? '1' : '0'))->assertForbidden();

        $this->assertNull($user->fresh()?->marketing_opt_out_at);
        $this->assertNull($other->fresh()?->marketing_opt_out_at);
    }

    public function test_unsubscribe_works_with_the_shop_off(): void
    {
        config(['access.store_open' => false]);
        app(SettingService::class)->set(StoreSettings::KEY_SHOP, 'off');
        $user = User::factory()->create();

        $this->get($this->path($user))->assertOk();
        $this->post($this->path($user), ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertNotNull($user->fresh()?->marketing_opt_out_at);
    }
}
