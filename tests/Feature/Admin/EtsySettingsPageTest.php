<?php

namespace Tests\Feature\Admin;

use App\Models\EtsyConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Alerts\FakesDiscord;
use Tests\Feature\Etsy\FakesEtsy;
use Tests\TestCase;

/**
 * Definicoes -> Etsy: ligar a loja (OAuth 2.0 com PKCE), ver em que estado
 * esta a ligacao e desliga-la.
 */
class EtsySettingsPageTest extends TestCase
{
    use FakesDiscord;
    use FakesEtsy;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureEtsy();
        $this->fakeDiscord();

        $this->admin = User::factory()->admin()->create();
    }

    private function confirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function fakeTokenExchange(): void
    {
        Http::fake([
            'etsy.test/v3/public/oauth/token' => Http::response([
                'access_token' => '9001.acesso-novo',
                'refresh_token' => '9001.renovacao-nova',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'etsy.test/v3/application/users/me' => Http::response(['user_id' => 9001, 'shop_id' => 4242]),
            'etsy.test/v3/application/shops/4242' => Http::response(['shop_id' => 4242, 'shop_name' => 'DozeStudio']),
        ]);
    }

    // ── Quem entra ─────────────────────────────────────────────────────────

    public function test_the_page_asks_for_the_password_again(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.etsy.index'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_customers_and_production_cannot_open_it(): void
    {
        $this->confirmed(User::factory()->create())->get(route('admin.etsy.index'))->assertForbidden();
        $this->confirmed(User::factory()->production()->create())->get(route('admin.etsy.index'))->assertForbidden();
    }

    // ── O que a pagina mostra ──────────────────────────────────────────────

    public function test_it_says_when_the_keys_are_missing(): void
    {
        config(['etsy.keystring' => null]);

        $this->confirmed($this->admin)
            ->get(route('admin.etsy.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/definicoes/etsy')
                ->where('configured', false)
                ->where('connection', null)
                // O endereco a registar na app da Etsy tem de bater a letra.
                ->where('redirectUri', 'https://12studio.test/admin/definicoes/etsy/callback'));
    }

    public function test_it_shows_the_connected_shop_but_never_the_tokens(): void
    {
        $this->connectEtsy();

        $response = $this->confirmed($this->admin)->get(route('admin.etsy.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('configured', true)
            ->where('enabled', true)
            ->where('connection.shopName', 'DozeStudio')
            ->where('connection.shopId', 4242)
            ->where('connection.broken', false));

        $this->assertStringNotContainsString('acesso-valido', (string) $response->getContent());
        $this->assertStringNotContainsString('renovacao-valida', (string) $response->getContent());
    }

    // ── Ligar ──────────────────────────────────────────────────────────────

    public function test_connecting_sends_the_owner_to_etsy_with_pkce(): void
    {
        $response = $this->confirmed($this->admin)->get(route('admin.etsy.ligar'));

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://www.etsy.com/oauth/connect?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('chave-de-teste', $query['client_id']);
        $this->assertSame('https://12studio.test/admin/definicoes/etsy/callback', $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(implode(' ', (array) config('etsy.scopes')), $query['scope']);

        // O desafio e o SHA-256 do verificador que ficou na sessao — o
        // verificador em si nunca passa pelo browser.
        $verifier = (string) session('etsy.oauth.verifier');
        $this->assertGreaterThanOrEqual(43, strlen($verifier));
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $query['code_challenge'],
        );
        $this->assertSame(session('etsy.oauth.state'), $query['state']);
        $this->assertStringNotContainsString($verifier, $location);
    }

    public function test_connecting_without_keys_goes_nowhere(): void
    {
        config(['etsy.shared_secret' => null]);

        $this->confirmed($this->admin)
            ->from(route('admin.etsy.index'))
            ->get(route('admin.etsy.ligar'))
            ->assertRedirect(route('admin.etsy.index'));
    }

    public function test_connecting_with_the_integration_off_goes_nowhere(): void
    {
        $this->configureEtsy(enabled: false);

        // Desligada e desligada: nem o aperto de mao sai.
        $this->confirmed($this->admin)
            ->get(route('admin.etsy.ligar'))
            ->assertRedirect(route('admin.etsy.index'))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');

        $this->assertNull(session('etsy.oauth'));
    }

    public function test_the_callback_stores_the_shop_and_starts_counting_orders_from_now(): void
    {
        $this->fakeTokenExchange();
        $this->freezeTime();

        $this->actingAs($this->admin)
            ->withSession(['etsy.oauth' => ['state' => 'estado-certo', 'verifier' => str_repeat('v', 64)]])
            ->get(route('admin.etsy.callback', ['code' => 'codigo-da-etsy', 'state' => 'estado-certo']))
            ->assertRedirect(route('admin.etsy.index'));

        $exchange = $this->etsyRequests('/oauth/token')->sole();
        $this->assertSame('authorization_code', $exchange['grant_type']);
        $this->assertSame('codigo-da-etsy', $exchange['code']);
        $this->assertSame(str_repeat('v', 64), $exchange['code_verifier']);
        $this->assertSame('https://12studio.test/admin/definicoes/etsy/callback', $exchange['redirect_uri']);

        $connection = EtsyConnection::query()->sole();
        $this->assertSame(4242, $connection->shop_id);
        $this->assertSame('DozeStudio', $connection->shop_name);
        $this->assertSame(9001, $connection->etsy_user_id);
        $this->assertSame('9001.acesso-novo', $connection->access_token);
        $this->assertSame($this->admin->id, $connection->connected_by_user_id);
        // Daqui para a frente: importar para tras descontava stock de
        // encomendas que ja sairam ha meses.
        $this->assertSame(now()->timestamp, $connection->orders_since?->timestamp);

        $this->assertNull(session('etsy.oauth'));
        $this->assertAlerted('seguranca', 'Etsy');
    }

    public function test_a_callback_with_the_wrong_state_is_thrown_away(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->withSession(['etsy.oauth' => ['state' => 'estado-certo', 'verifier' => str_repeat('v', 64)]])
            ->get(route('admin.etsy.callback', ['code' => 'codigo-de-outro', 'state' => 'estado-forjado']))
            ->assertRedirect(route('admin.etsy.index'));

        // Nem chega a trocar o codigo: um pedido que nao comecou aqui nao
        // liga loja nenhuma a esta conta.
        $this->assertCount(0, $this->etsyRequests());
        $this->assertSame(0, EtsyConnection::query()->count());
    }

    public function test_a_callback_nobody_asked_for_is_thrown_away(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->get(route('admin.etsy.callback', ['code' => 'codigo', 'state' => 'qualquer']))
            ->assertRedirect(route('admin.etsy.index'));

        $this->assertCount(0, $this->etsyRequests());
        $this->assertSame(0, EtsyConnection::query()->count());
    }

    public function test_a_callback_with_a_malformed_state_is_refused_politely(): void
    {
        Http::fake();

        // `?state[]=x`: um array onde se espera texto. Tem de ser uma recusa,
        // nao um erro 500 com o endereco do callback a caminho do #sistema.
        $this->actingAs($this->admin)
            ->withSession(['etsy.oauth' => ['state' => 'estado-certo', 'verifier' => str_repeat('v', 64)]])
            ->get(route('admin.etsy.callback').'?code=codigo&state[]=estado-certo')
            ->assertRedirect(route('admin.etsy.index'))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');

        $this->assertCount(0, $this->etsyRequests());
        $this->assertSame(0, EtsyConnection::query()->count());
    }

    public function test_a_token_answer_missing_a_token_never_shows_what_came(): void
    {
        Http::fake(['etsy.test/v3/public/oauth/token' => Http::response([
            'access_token' => '9001.acesso-que-nao-pode-aparecer',
            'expires_in' => 3600,
        ])]);

        // 200 mas sem token de renovacao: nao da para ligar. A mensagem nao
        // pode trazer o corpo da resposta — vinha la o token de acesso.
        $response = $this->actingAs($this->admin)
            ->withSession(['etsy.oauth' => ['state' => 'estado-certo', 'verifier' => str_repeat('v', 64)]])
            ->get(route('admin.etsy.callback', ['code' => 'codigo-da-etsy', 'state' => 'estado-certo']));

        $response->assertRedirect(route('admin.etsy.index'))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error')
            ->assertSessionHas(
                'inertia.flash_data.toast.message',
                fn (string $message): bool => ! str_contains($message, 'acesso-que-nao-pode-aparecer'),
            );

        $this->assertSame(0, EtsyConnection::query()->count());
    }

    public function test_the_switch_flipped_mid_way_stops_the_exchange(): void
    {
        $this->configureEtsy(enabled: false);
        Http::fake();

        // O dono saiu para a Etsy com a integracao ligada e voltou com ela
        // desligada. Desligada e desligada: a troca do codigo tambem nao sai.
        $this->actingAs($this->admin)
            ->withSession(['etsy.oauth' => ['state' => 'estado-certo', 'verifier' => str_repeat('v', 64)]])
            ->get(route('admin.etsy.callback', ['code' => 'codigo-da-etsy', 'state' => 'estado-certo']))
            ->assertRedirect(route('admin.etsy.index'))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');

        Http::assertNothingSent();
        $this->assertSame(0, EtsyConnection::query()->count());
    }

    public function test_refusing_on_etsy_comes_back_without_a_connection(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->withSession(['etsy.oauth' => ['state' => 'estado-certo', 'verifier' => str_repeat('v', 64)]])
            ->get(route('admin.etsy.callback', ['error' => 'access_denied', 'state' => 'estado-certo']))
            ->assertRedirect(route('admin.etsy.index'));

        $this->assertSame(0, EtsyConnection::query()->count());
    }

    public function test_connecting_again_replaces_the_connection_but_keeps_the_order_cursor(): void
    {
        $since = now()->subMonth()->startOfSecond();
        $this->connectEtsy(['orders_since' => $since, 'broken_at' => now(), 'last_error' => 'invalid_grant']);
        $this->fakeTokenExchange();

        $this->actingAs($this->admin)
            ->withSession(['etsy.oauth' => ['state' => 'estado-certo', 'verifier' => str_repeat('v', 64)]])
            ->get(route('admin.etsy.callback', ['code' => 'codigo-da-etsy', 'state' => 'estado-certo']));

        $connection = EtsyConnection::query()->sole();
        $this->assertSame('9001.acesso-novo', $connection->access_token);
        $this->assertNull($connection->broken_at);
        $this->assertNull($connection->last_error);
        // Religar a mesma loja nao e comecar de novo: as encomendas que
        // entraram enquanto a ligacao esteve partida ainda tem de vir.
        $this->assertTrue($since->equalTo($connection->orders_since));
    }

    public function test_the_callback_is_for_admins_only(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.etsy.callback', ['code' => 'c', 'state' => 's']))
            ->assertForbidden();
    }

    // ── Testar e desligar ──────────────────────────────────────────────────

    public function test_testing_asks_etsy_who_we_are(): void
    {
        $this->connectEtsy();
        Http::fake(['etsy.test/v3/application/users/me' => Http::response(['user_id' => 9001, 'shop_id' => 4242])]);

        $this->confirmed($this->admin)
            ->from(route('admin.etsy.index'))
            ->post(route('admin.etsy.testar'))
            ->assertRedirect(route('admin.etsy.index'))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        $this->assertCount(1, $this->etsyRequests('/users/me'));
    }

    public function test_a_failed_test_says_what_etsy_answered(): void
    {
        $this->connectEtsy();
        Http::fake(['etsy.test/*' => Http::response(['error' => 'Invalid API key'], 403)]);

        $this->confirmed($this->admin)
            ->from(route('admin.etsy.index'))
            ->post(route('admin.etsy.testar'))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error')
            ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message): bool => str_contains($message, 'Invalid API key'));
    }

    public function test_disconnecting_forgets_the_tokens(): void
    {
        $this->connectEtsy();

        $this->confirmed($this->admin)
            ->from(route('admin.etsy.index'))
            ->delete(route('admin.etsy.desligar'))
            ->assertRedirect(route('admin.etsy.index'));

        $this->assertSame(0, EtsyConnection::query()->count());
        $this->assertAlerted('seguranca', 'Etsy');
    }
}
