<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\WhoAmITool;
use App\Models\McpActivity;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

/**
 * O /mcp por HTTP, com tokens reais do Passport: e isto que o Claude faz.
 */
class McpSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['mcp.enabled' => true]);

        app(ClientRepository::class)->createPersonalAccessGrantClient('Chaves de API do 12studio', 'users');
    }

    /**
     * @return array<string, mixed>
     */
    private function listTools(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $arguments = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => (object) $arguments],
        ];
    }

    private function tokenFor(User $user, string $access = ApiKeyService::ACCESS_READ, int $days = 30): string
    {
        return app(ApiKeyService::class)->create($user, 'Teste', $access, $days)['token'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mcp(?string $token, array $payload): TestResponse
    {
        $headers = ['Accept' => 'application/json, text/event-stream'];

        if ($token !== null) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $this->postJson('/mcp', $payload, $headers);
    }

    public function test_without_a_token_it_is_401(): void
    {
        $this->mcp(null, $this->listTools())->assertUnauthorized();
    }

    public function test_a_browser_session_is_not_enough(): void
    {
        // Admin com sessao web aberta, mas sem Bearer: o /mcp nao conhece
        // sessoes, por isso um site malicioso nao consegue usar a do admin.
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->mcp(null, $this->listTools())->assertUnauthorized();
    }

    public function test_a_valid_admin_key_lists_the_tools(): void
    {
        $token = $this->tokenFor(User::factory()->admin()->withTwoFactor()->create());

        $this->mcp($token, $this->listTools())
            ->assertOk()
            ->assertJsonFragment(['name' => 'whoami']);
    }

    public function test_a_read_key_sees_exactly_the_read_tools(): void
    {
        $token = $this->tokenFor(User::factory()->admin()->create());

        $tools = collect($this->mcp($token, $this->listTools())->assertOk()->json('result.tools'));

        $this->assertEqualsCanonicalizing([
            'whoami', 'products_list', 'product_get', 'pricing_preview', 'stock_low',
            'stock_movements', 'categories_list', 'tags_list', 'colors_list',
            'materials_list', 'orders_list', 'order_get',
        ], $tools->pluck('name')->all());

        // Todas marcadas como so leitura, para o cliente nao pedir confirmacao.
        $this->assertTrue($tools->every(fn (array $tool): bool => ($tool['annotations']['readOnlyHint'] ?? false) === true));
    }

    public function test_a_write_key_also_sees_the_write_tools(): void
    {
        $token = $this->tokenFor(User::factory()->admin()->create(), ApiKeyService::ACCESS_WRITE);

        $tools = collect($this->mcp($token, $this->listTools())->assertOk()->json('result.tools'))->keyBy('name');

        $this->assertCount(22, $tools);
        $this->assertFalse($tools['variant_update']['annotations']['readOnlyHint'] ?? false);
        // Arquivar leva o aviso de destrutivo; editar um preco nao.
        $this->assertTrue($tools['product_archive']['annotations']['destructiveHint'] ?? false);
        $this->assertFalse($tools['variant_update']['annotations']['destructiveHint'] ?? true);
    }

    public function test_whoami_reports_the_access_level(): void
    {
        $token = $this->tokenFor(User::factory()->admin()->create(), ApiKeyService::ACCESS_WRITE);

        $this->mcp($token, $this->callTool('whoami'))
            ->assertOk()
            ->assertSee('leitura e escrita');
    }

    public function test_a_non_admin_key_is_refused(): void
    {
        $customer = User::factory()->create();
        $token = $customer->createToken('x', [ApiKeyService::SCOPE_READ])->accessToken;

        $this->mcp($token, $this->listTools())->assertForbidden();
    }

    public function test_production_staff_cannot_use_the_mcp(): void
    {
        $staff = User::factory()->production()->create();
        $token = $staff->createToken('x', [ApiKeyService::SCOPE_READ, ApiKeyService::SCOPE_WRITE])->accessToken;

        $this->mcp($token, $this->listTools())->assertForbidden();
    }

    public function test_a_key_without_the_mcp_scope_is_refused(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('x', [])->accessToken;

        $this->mcp($token, $this->listTools())->assertForbidden();

        $this->assertDatabaseHas('mcp_activity', ['user_id' => $admin->id, 'result' => McpActivity::RESULT_DENIED]);
    }

    public function test_a_demoted_admin_loses_the_key_at_once(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->tokenFor($admin);

        $admin->is_admin = false;
        $admin->save();

        $this->mcp($token, $this->listTools())->assertStatus(401);
    }

    public function test_changing_the_password_revokes_the_keys(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->tokenFor($admin);

        $admin->password = 'Outra-password-bem-longa-7';
        $admin->save();

        $this->mcp($token, $this->listTools())->assertUnauthorized();
    }

    public function test_disabling_the_account_revokes_the_keys(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->tokenFor($admin);

        $admin->disabled_at = now();
        $admin->save();

        $this->mcp($token, $this->listTools())->assertUnauthorized();
    }

    public function test_turning_2fa_off_revokes_the_keys(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $token = $this->tokenFor($admin);

        event(new TwoFactorAuthenticationDisabled($admin));

        $this->mcp($token, $this->listTools())->assertUnauthorized();
    }

    public function test_a_revoked_key_is_refused(): void
    {
        $admin = User::factory()->admin()->create();
        $created = app(ApiKeyService::class)->create($admin, 'Teste', ApiKeyService::ACCESS_READ, 30);

        app(ApiKeyService::class)->revoke($admin, (string) $created['key']->getKey());

        $this->mcp($created['token'], $this->listTools())->assertUnauthorized();
    }

    public function test_the_lifetime_chosen_for_the_key_is_enforced(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->tokenFor($admin, days: 7);

        $this->travel(8)->days();

        $this->mcp($token, $this->listTools())->assertUnauthorized();
    }

    public function test_the_kill_switch_turns_everything_off(): void
    {
        $token = $this->tokenFor(User::factory()->admin()->create());
        config(['mcp.enabled' => false]);

        $this->mcp($token, $this->listTools())->assertServiceUnavailable();
    }

    public function test_the_rate_limit_answers_429(): void
    {
        $token = $this->tokenFor(User::factory()->admin()->create());

        for ($i = 0; $i < 60; $i++) {
            $this->mcp($token, $this->listTools())->assertOk();
        }

        $this->mcp($token, $this->listTools())->assertTooManyRequests();
    }

    public function test_errors_never_carry_a_stack_trace(): void
    {
        config(['app.debug' => false]);

        $this->mcp(null, $this->listTools())
            ->assertUnauthorized()
            ->assertDontSee('vendor/laravel')
            ->assertDontSee('#0 ');
    }

    public function test_tool_calls_are_audited(): void
    {
        $admin = User::factory()->admin()->create();

        StudioServer::actingAs($admin)->tool(WhoAmITool::class)->assertOk();

        $this->assertDatabaseHas('mcp_activity', [
            'user_id' => $admin->id,
            'tool' => 'whoami',
            'result' => McpActivity::RESULT_OK,
        ]);
    }
}
