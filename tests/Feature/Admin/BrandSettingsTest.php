<?php

namespace Tests\Feature\Admin;

use App\Mail\OrderShippedMail;
use App\Mail\StaffAccountChangedMail;
use App\Models\Order;
use App\Models\User;
use App\Services\ApiKeyService;
use App\Services\BrandSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

/**
 * O nome da loja vive nas definicoes e nao no codigo: a mesma instalacao vai
 * servir mais do que uma loja, e cada uma tem de aparecer com o seu nome.
 */
class BrandSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function rename(string $name, string $tagline = ''): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('admin.definicoes.marca'), ['name' => $name, 'tagline' => $tagline])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.definicoes.index'));
    }

    public function test_without_settings_the_name_comes_from_the_config(): void
    {
        config(['shop.name' => 'Loja de Omissao', 'shop.tagline' => 'feito à mão']);

        $brand = app(BrandSettings::class);

        $this->assertSame('Loja de Omissao', $brand->name());
        $this->assertSame('feito à mão', $brand->tagline());
    }

    public function test_every_page_gets_the_brand_as_a_shared_prop(): void
    {
        $this->rename('Oficina do Rui', 'peças em PETG');

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->where('brand.name', 'Oficina do Rui')
            ->where('brand.tagline', 'peças em PETG')
            ->missing('name'));
    }

    public function test_the_html_title_uses_the_brand(): void
    {
        $this->rename('Oficina do Rui');

        $this->get(route('home'))->assertSee('<title>Oficina do Rui</title>', false);
    }

    public function test_the_settings_page_shows_the_current_brand(): void
    {
        $this->rename('Oficina do Rui', 'peças em PETG');

        $this->get(route('admin.definicoes.index'))->assertInertia(fn (Assert $page) => $page
            ->where('brand', ['name' => 'Oficina do Rui', 'tagline' => 'peças em PETG']));
    }

    public function test_the_name_is_trimmed_and_the_tagline_may_be_empty(): void
    {
        $this->rename('  Oficina do Rui  ', '');

        $brand = app(BrandSettings::class);
        $this->assertSame('Oficina do Rui', $brand->name());
        $this->assertSame('', $brand->tagline());
    }

    public function test_the_name_is_required_and_short(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.definicoes.marca'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->patch(route('admin.definicoes.marca'), ['name' => str_repeat('a', 41)])
            ->assertSessionHasErrors('name');
    }

    public function test_only_admins_can_rename_the_shop(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch(route('admin.definicoes.marca'), ['name' => 'Roubada'])
            ->assertForbidden();

        $this->actingAs(User::factory()->production()->create())
            ->patch(route('admin.definicoes.marca'), ['name' => 'Roubada'])
            ->assertForbidden();

        $this->assertNotSame('Roubada', app(BrandSettings::class)->name());
    }

    public function test_emails_are_signed_with_the_brand(): void
    {
        $this->rename('Oficina do Rui');

        $order = Order::factory()->create();
        $this->assertStringContainsString('Oficina do Rui', (new OrderShippedMail($order))->render());

        $owner = User::factory()->owner()->create();
        $staff = User::factory()->production()->create();
        $mail = new StaffAccountChangedMail($staff, $owner, 'Conta criada');

        $mail->assertHasSubject("Equipa Oficina do Rui: Conta criada — {$staff->name}");
        $this->assertStringContainsString('Oficina do Rui', $mail->render());
        $this->assertStringNotContainsString('12studio', $mail->render());
    }

    public function test_the_mcp_server_introduces_itself_with_the_brand(): void
    {
        Mail::fake();
        config(['mcp.enabled' => true]);
        app(ClientRepository::class)->createPersonalAccessGrantClient('Chaves de API', 'users');

        $this->rename('Oficina do Rui');

        $admin = User::factory()->admin()->create();
        $token = app(ApiKeyService::class)->create($admin, 'Teste', ApiKeyService::ACCESS_READ, 30)['token'];

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'teste', 'version' => '1.0'],
            ],
        ], [
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk();
        $this->assertSame('Oficina do Rui', $response->json('result.serverInfo.name'));
        $this->assertStringContainsString('Oficina do Rui', (string) $response->json('result.instructions'));
    }

    public function test_the_api_key_command_uses_a_slug_of_the_brand(): void
    {
        $this->rename('Oficina do Rui');

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.chaves-api.index'))
            ->assertInertia(fn (Assert $page) => $page->where('mcpServerName', 'oficina-do-rui'));
    }
}
