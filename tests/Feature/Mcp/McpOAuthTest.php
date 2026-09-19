<?php

namespace Tests\Feature\Mcp;

use App\Mail\ApiKeyCreatedMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * O caminho do claude.ai / telemovel: descoberta, registo dinamico,
 * consentimento e troca do codigo por um token — contra as rotas reais.
 */
class McpOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['mcp.enabled' => true]);
    }

    private function register(string $redirect = self::REDIRECT): TestResponse
    {
        return $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [$redirect],
        ]);
    }

    /**
     * @return array{client_id: string, verifier: string, query: array<string, string>}
     */
    private function authorizationRequest(string $scope = 'mcp:read mcp:write'): array
    {
        $clientId = (string) $this->register()->assertCreated()->json('client_id');
        $verifier = Str::random(64);

        return [
            'client_id' => $clientId,
            'verifier' => $verifier,
            'query' => [
                'client_id' => $clientId,
                'redirect_uri' => self::REDIRECT,
                'response_type' => 'code',
                'scope' => $scope,
                'state' => 'estado-123',
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
                'code_challenge_method' => 'S256',
            ],
        ];
    }

    private function confirmedAdminWith2fa(): User
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);

        return $admin;
    }

    // ── Descoberta ─────────────────────────────────────────────────────────

    public function test_the_mcp_401_points_to_the_resource_metadata(): void
    {
        $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized();

        $this->assertStringContainsString(
            'resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"',
            (string) $response->headers->get('WWW-Authenticate'),
        );
    }

    public function test_the_metadata_announces_our_scopes(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp')
            ->assertOk()
            ->assertJsonPath('scopes_supported', ['mcp:read', 'mcp:write'])
            ->assertJsonPath('resource', url('/mcp'));

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('scopes_supported', ['mcp:read', 'mcp:write'])
            ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
            ->assertJsonPath('token_endpoint', route('passport.token'))
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    // ── Registo dinamico ───────────────────────────────────────────────────

    public function test_claude_can_register_and_gets_our_scopes(): void
    {
        $this->register()
            ->assertCreated()
            ->assertJsonPath('scope', 'mcp:read mcp:write');
    }

    public function test_any_other_site_is_refused(): void
    {
        $this->register('https://evil.example/callback')
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');

        $this->register('https://claude.ai.evil.example/callback')->assertStatus(400);

        $this->assertSame(0, Passport::client()->newQuery()->count());
    }

    public function test_registration_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->register()->assertCreated();
        }

        $this->register()->assertTooManyRequests();
    }

    // ── Consentimento ──────────────────────────────────────────────────────

    public function test_a_guest_is_sent_to_login(): void
    {
        $request = $this->authorizationRequest();

        $this->get(route('passport.authorizations.authorize', $request['query']))
            ->assertRedirect(route('login'));
    }

    public function test_only_admins_can_authorize(): void
    {
        $request = $this->authorizationRequest();

        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('passport.authorizations.authorize', $request['query']))
            ->assertForbidden();

        $this->actingAs(User::factory()->production()->withTwoFactor()->create())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('passport.authorizations.authorize', $request['query']))
            ->assertForbidden();
    }

    public function test_the_password_is_asked_again(): void
    {
        $request = $this->authorizationRequest();

        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->get(route('passport.authorizations.authorize', $request['query']))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_without_a_second_factor_there_is_no_consent(): void
    {
        $request = $this->authorizationRequest();

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('passport.authorizations.authorize', $request['query']))
            ->assertRedirect(route('security.edit'));
    }

    public function test_the_consent_page_shows_where_the_access_goes(): void
    {
        $request = $this->authorizationRequest();
        $this->confirmedAdminWith2fa();

        $response = $this->get(route('passport.authorizations.authorize', $request['query']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/oauth-authorize')
                ->where('redirectHost', 'claude.ai')
                ->where('clientName', 'Claude')
                ->where('canWrite', true)
                ->has('scopes', 2));

        // O form de aprovacao faz POST e o servidor responde com um redirect
        // para o claude.ai: o CSP tem de o permitir nesta pagina.
        $this->assertStringContainsString(
            'form-action \'self\' https://claude.ai https://claude.com',
            (string) $response->headers->get('Content-Security-Policy-Report-Only'),
        );
    }

    // ── Fluxo completo ─────────────────────────────────────────────────────

    public function test_the_full_flow_ends_in_a_working_token(): void
    {
        $request = $this->authorizationRequest();
        $admin = $this->confirmedAdminWith2fa();

        $page = $this->get(route('passport.authorizations.authorize', $request['query']))->assertOk();
        $authToken = $page->viewData('page')['props']['authToken'];

        $approved = $this->post(route('passport.authorizations.approve'), ['auth_token' => $authToken]);
        $location = (string) $approved->headers->get('Location');

        $this->assertStringStartsWith(self::REDIRECT.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $callback);
        $this->assertSame('estado-123', $callback['state']);

        Mail::assertQueued(ApiKeyCreatedMail::class, fn ($mail) => $mail->hasTo($admin->email));

        $token = $this->postJson(route('passport.token'), [
            'grant_type' => 'authorization_code',
            'client_id' => $request['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $request['verifier'],
            'code' => $callback['code'],
        ])->assertOk()->json('access_token');

        $this->app['auth']->forgetGuards();

        $tools = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []], [
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => "Bearer {$token}",
        ])->assertOk()->json('result.tools');

        $this->assertContains('variant_update', array_column($tools, 'name'));
    }

    public function test_denying_sends_the_user_back_with_an_error(): void
    {
        $request = $this->authorizationRequest();
        $this->confirmedAdminWith2fa();

        $page = $this->get(route('passport.authorizations.authorize', $request['query']))->assertOk();

        $denied = $this->delete(route('passport.authorizations.deny'), [
            'auth_token' => $page->viewData('page')['props']['authToken'],
        ]);

        $this->assertStringContainsString('error=access_denied', (string) $denied->headers->get('Location'));
        Mail::assertNothingQueued();
    }

    public function test_a_read_only_grant_does_not_see_the_write_tools(): void
    {
        $request = $this->authorizationRequest('mcp:read');
        $this->confirmedAdminWith2fa();

        $page = $this->get(route('passport.authorizations.authorize', $request['query']))
            ->assertInertia(fn (Assert $page) => $page->where('canWrite', false));

        $approved = $this->post(route('passport.authorizations.approve'), [
            'auth_token' => $page->viewData('page')['props']['authToken'],
        ]);
        parse_str((string) parse_url((string) $approved->headers->get('Location'), PHP_URL_QUERY), $callback);

        $token = $this->postJson(route('passport.token'), [
            'grant_type' => 'authorization_code',
            'client_id' => $request['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $request['verifier'],
            'code' => $callback['code'],
        ])->json('access_token');

        $this->app['auth']->forgetGuards();

        $tools = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []], [
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => "Bearer {$token}",
        ])->assertOk()->json('result.tools');

        $this->assertNotContains('variant_update', array_column($tools, 'name'));
    }

    // ── O que NAO existe ───────────────────────────────────────────────────

    public function test_passport_json_api_and_device_routes_stay_off(): void
    {
        $this->actingAs(User::factory()->admin()->withTwoFactor()->create());

        // Criar chaves pela sessao, sem password nem 2FA, seria o atalho a
        // volta da pagina de chaves.
        $this->postJson('/oauth/personal-access-tokens', ['name' => 'x'])->assertNotFound();
        $this->getJson('/oauth/clients')->assertNotFound();
        $this->postJson('/oauth/device/code')->assertNotFound();
    }

    // ── Limpeza ────────────────────────────────────────────────────────────

    public function test_clients_that_were_never_authorized_are_pruned(): void
    {
        $old = Passport::client()->newQuery()->findOrFail($this->register()->json('client_id'));
        $old->forceFill(['created_at' => now()->subDays(2)])->save();

        $used = Passport::client()->newQuery()->findOrFail($this->register()->json('client_id'));
        $used->forceFill(['created_at' => now()->subDays(2)])->save();
        $used->tokens()->create([
            'id' => Str::random(40), 'user_id' => User::factory()->admin()->create()->id,
            'name' => null, 'scopes' => [], 'revoked' => true, 'expires_at' => now(),
        ]);

        $fresh = Passport::client()->newQuery()->findOrFail($this->register()->json('client_id'));

        app(ClientRepository::class)->createPersonalAccessGrantClient('Chaves de API do 12studio', 'users');

        $this->artisan('mcp:prune-clients')->assertSuccessful();

        $ids = Passport::client()->newQuery()->pluck('id');
        $this->assertNotContains($old->id, $ids);
        $this->assertContains($used->id, $ids);
        $this->assertContains($fresh->id, $ids);
        $this->assertTrue(Passport::client()->newQuery()->get()->contains(
            fn (Client $client): bool => $client->hasGrantType('personal_access'),
        ));
    }
}
