<?php

namespace Tests\Feature\Alerts;

use App\Alerts\AlertChannel;
use App\Mcp\McpAuditor;
use App\Models\McpActivity;
use App\Models\User;
use App\Services\ApiKeyService;
use App\Services\StaffService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class SecurityAlertsTest extends TestCase
{
    use FakesDiscord;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeDiscord();

        app(ClientRepository::class)->createPersonalAccessGrantClient('Chaves de API do 12studio', 'users');
    }

    public function test_a_login_says_who_and_from_where(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Rita']);

        Auth::guard('web')->login($admin);

        $text = $this->embedText($this->alertEmbed(AlertChannel::SECURITY, 'Entrou — Rita'));
        $this->assertStringContainsString('Administrador', $text);
        $this->assertStringContainsString('IP', $text);
    }

    public function test_a_failed_login_never_carries_the_password(): void
    {
        $user = User::factory()->admin()->create(['email' => 'rita@12studio.test', 'name' => 'Rita']);

        Auth::guard('web')->attempt(['email' => 'rita@12studio.test', 'password' => 'palpite-secreto-123']);

        $this->assertAlerted(AlertChannel::SECURITY, 'Login falhado — Rita');
        Http::assertSent(fn ($request): bool => ! str_contains($request->body(), 'palpite-secreto-123'));
        $this->assertTrue($user->exists);
    }

    public function test_a_disabled_account_trying_to_enter_is_named(): void
    {
        User::factory()->admin()->create(['email' => 'ex@12studio.test', 'name' => 'Ex', 'disabled_at' => now()]);

        // Como o Fortify o dispara quando o authenticateUsing recusa: sem user.
        event(new Failed('web', null, ['email' => 'ex@12studio.test', 'password' => 'x']));

        $this->assertStringContainsString(
            'Conta desativada',
            $this->embedText($this->alertEmbed(AlertChannel::SECURITY, 'Login falhado — Ex')),
        );
    }

    public function test_a_lockout_is_red(): void
    {
        event(new Lockout(Request::create('/login', 'POST', ['email' => 'alvo@12studio.test'])));

        $embed = $this->alertEmbed(AlertChannel::SECURITY, 'Bloqueado por excesso de tentativas');
        $this->assertStringContainsString('alvo@12studio.test', $this->embedText($embed));
    }

    public function test_a_password_change_says_how_many_keys_died(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Rita']);
        app(ApiKeyService::class)->create($admin, 'Portátil', 'read', 30);
        app(ApiKeyService::class)->create($admin, 'Servidor', 'write', null);

        $admin->update(['password' => 'Outra-password-bem-longa-7']);

        $this->assertStringContainsString(
            'Chaves de API revogadas: 2',
            $this->embedText($this->alertEmbed(AlertChannel::SECURITY, 'Password mudada — Rita')),
        );
    }

    public function test_team_changes_arrive_without_a_duplicate_for_passwords(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Dono']);
        $staff = app(StaffService::class)->create([
            'name' => 'Rita',
            'email' => 'rita@12studio.test',
            'role' => User::ROLE_PRODUCTION,
            'password' => 'Uma-password-bem-longa-1',
        ], $owner);

        $this->assertAlerted(AlertChannel::SECURITY, 'Equipa: Conta criada (produção) — Rita');

        app(StaffService::class)->resetPassword($staff, 'Outra-password-bem-longa-2', $owner);

        $this->assertAlerted(AlertChannel::SECURITY, 'Password mudada — Rita');
        $this->assertNotAlerted(AlertChannel::SECURITY, 'Password redefinida');
    }

    public function test_a_key_without_expiry_is_flagged(): void
    {
        $admin = User::factory()->admin()->create();

        app(ApiKeyService::class)->create($admin, 'Servidor', 'write', null);

        $embed = $this->alertEmbed(AlertChannel::SECURITY, 'Chave de API criada — Servidor');
        $this->assertStringContainsString('Sem validade', $this->embedText($embed));
        $this->assertStringContainsString('Leitura e escrita', $this->embedText($embed));
    }

    public function test_revoking_a_key_is_reported(): void
    {
        $admin = User::factory()->admin()->create();
        $created = app(ApiKeyService::class)->create($admin, 'Portátil', 'read', 30);

        app(ApiKeyService::class)->revoke($admin, (string) $created['key']->getKey());

        $this->assertAlerted(AlertChannel::SECURITY, 'Chave revogada — Portátil');
    }

    public function test_revoke_all_from_the_page_is_reported_once(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Rita']);
        app(ApiKeyService::class)->create($admin, 'A', 'read', 30);
        app(ApiKeyService::class)->create($admin, 'B', 'read', 30);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('admin.chaves-api.destroy-all'));

        $this->assertStringContainsString(
            'Chaves revogadas: 2',
            $this->embedText($this->alertEmbed(AlertChannel::SECURITY, 'Revogar tudo — Rita')),
        );
    }

    public function test_demoting_an_admin_reports_the_keys_that_died(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Rita']);
        app(ApiKeyService::class)->create($admin, 'Portátil', 'read', 30);

        $admin->is_admin = false;
        $admin->save();

        $this->assertStringContainsString(
            'O papel mudou',
            $this->embedText($this->alertEmbed(AlertChannel::SECURITY, '1 chave revogada — Rita')),
        );
    }

    public function test_claude_writes_show_what_changed(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Rita']);

        app(McpAuditor::class)->record(
            $admin,
            'variant_update',
            McpActivity::RESULT_OK,
            ['variant_id' => 7, 'price' => '19.90'],
            ['before' => ['price_cents' => 1790], 'after' => ['price_cents' => 1990]],
        );

        $text = $this->embedText($this->alertEmbed(AlertChannel::SECURITY, 'Claude alterou — variant_update'));
        $this->assertStringContainsString('"price_cents": 1990', $text);
    }

    public function test_claude_reads_wait_for_the_digest(): void
    {
        $admin = User::factory()->admin()->create();

        app(McpAuditor::class)->record($admin, 'products_list', McpActivity::RESULT_OK);

        $this->assertSame([], $this->alertTitles(AlertChannel::SECURITY));
    }

    public function test_a_refused_mcp_call_is_red(): void
    {
        app(McpAuditor::class)->record(null, '(ligação)', McpActivity::RESULT_DENIED);

        $this->assertAlerted(AlertChannel::SECURITY, 'MCP recusado — (ligação)');
    }

    public function test_every_oauth_registration_is_reported(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->assertCreated();

        $text = $this->embedText($this->alertEmbed(AlertChannel::SECURITY, 'Cliente OAuth registado — Claude'));
        $this->assertStringContainsString('https://claude.ai/api/mcp/auth_callback', $text);
    }

    public function test_two_factor_changes_are_reported(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Rita']);

        event(new TwoFactorAuthenticationConfirmed($admin));
        event(new TwoFactorAuthenticationDisabled($admin));

        $this->assertSame(['🛡️ 2FA ligado — Rita', '⚠️ 2FA desligado — Rita'], $this->alertTitles(AlertChannel::SECURITY));
    }
}
