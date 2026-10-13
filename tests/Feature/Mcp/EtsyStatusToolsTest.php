<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Etsy\EtsyStatusTool;
use App\Mcp\Tools\Etsy\EtsyUserGetTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Tests\Feature\Etsy\FakesEtsy;
use Tests\TestCase;

class EtsyStatusToolsTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureEtsy();

        $this->admin = User::factory()->admin()->create();
    }

    private function mcp(): PendingTestResponse
    {
        return StudioServer::actingAs($this->admin);
    }

    // ── etsy_status ────────────────────────────────────────────────────────

    public function test_status_says_where_to_connect_when_there_is_no_shop(): void
    {
        // Nao e um erro: e a resposta certa a "a Etsy esta ligada?".
        $this->mcp()->tool(EtsyStatusTool::class)
            ->assertOk()
            ->assertSee('Definições → Etsy');
    }

    public function test_status_shows_the_shop_and_never_the_tokens(): void
    {
        $this->connectEtsy();
        Http::fake(['etsy.test/v3/application/shops/4242' => Http::response([
            'shop_id' => 4242,
            'shop_name' => 'DozeStudio',
            'title' => 'Peças impressas em 3D',
            'currency_code' => 'EUR',
            'listing_active_count' => 12,
            // Nao e para aqui chamado: o estado so leva o que interessa.
            'user_id' => 9001,
        ])]);

        $this->mcp()->tool(EtsyStatusTool::class)
            ->assertOk()
            ->assertSee('DozeStudio')
            ->assertSee('Peças impressas em 3D')
            ->assertSee('listings_w')
            ->assertDontSee('acesso-valido')
            ->assertDontSee('renovacao-valida');
    }

    public function test_status_still_answers_when_etsy_is_down(): void
    {
        $this->connectEtsy();
        Http::fake(['etsy.test/*' => Http::response(['error' => 'Service unavailable'], 503)]);

        // E quando a Etsy esta em baixo que esta pergunta mais se faz.
        $this->mcp()->tool(EtsyStatusTool::class)
            ->assertOk()
            ->assertSee('DozeStudio')
            ->assertSee('Service unavailable');
    }

    public function test_status_says_when_the_integration_is_switched_off(): void
    {
        $this->configureEtsy(enabled: false);
        $this->connectEtsy();

        $this->mcp()->tool(EtsyStatusTool::class)
            ->assertOk()
            ->assertSee('ETSY_ENABLED');
    }

    public function test_status_says_when_the_connection_broke(): void
    {
        $this->connectEtsy(['broken_at' => now(), 'last_error' => 'invalid_grant']);

        $this->mcp()->tool(EtsyStatusTool::class)
            ->assertOk()
            ->assertSee('Volta a ligar');
    }

    // ── etsy_user_get ──────────────────────────────────────────────────────

    public function test_without_an_id_it_returns_the_connected_account_and_its_addresses(): void
    {
        $this->connectEtsy();
        Http::fake([
            'etsy.test/v3/application/users/9001' => Http::response([
                'user_id' => 9001,
                'primary_email' => 'goncalo@example.test',
                'first_name' => 'Gonçalo',
                'last_name' => 'Nogueira',
            ]),
            'etsy.test/v3/application/user/addresses*' => Http::response(['count' => 1, 'results' => [[
                'user_address_id' => 7,
                'name' => 'Gonçalo Nogueira',
                'first_line' => 'Rua das Flores 12',
                'city' => 'Porto',
                'zip' => '4000-123',
                'iso_country_code' => 'PT',
            ]]]),
        ]);

        $this->mcp()->tool(EtsyUserGetTool::class)
            ->assertOk()
            // O que a Etsy guarda como texto livre vem como dados, nao como
            // instrucoes; o email vem mascarado como o dos clientes.
            ->assertSee('<dados_cliente>Gonçalo Nogueira')
            ->assertSee('<dados_cliente>Rua das Flores 12')
            ->assertSee('Porto')
            ->assertDontSee('goncalo@example.test');
    }

    public function test_with_an_id_it_returns_that_user_only(): void
    {
        $this->connectEtsy();
        Http::fake(['etsy.test/v3/application/users/55' => Http::response([
            'user_id' => 55,
            'first_name' => 'Ana',
            'last_name' => 'Silva',
        ])]);

        $this->mcp()->tool(EtsyUserGetTool::class, ['user_id' => 55])
            ->assertOk()
            ->assertSee('Ana Silva');

        $this->assertCount(0, $this->etsyRequests('/user/addresses'));
    }

    public function test_what_etsy_refuses_comes_back_readable(): void
    {
        $this->connectEtsy();
        Http::fake(['etsy.test/*' => Http::response(['error' => 'User not found'], 404)]);

        $this->mcp()->tool(EtsyUserGetTool::class, ['user_id' => 55])
            ->assertHasErrors()
            ->assertSee('User not found');
    }

    public function test_without_a_shop_the_tools_say_where_to_connect(): void
    {
        Http::fake();

        $this->mcp()->tool(EtsyUserGetTool::class)
            ->assertHasErrors()
            ->assertSee('Definições → Etsy');

        Http::assertNothingSent();
    }
}
