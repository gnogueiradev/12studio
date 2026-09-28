<?php

namespace Tests\Feature\Alerts;

use App\Alerts\AlertChannel;
use App\Mcp\McpAuditor;
use App\Models\Material;
use App\Models\McpActivity;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class AlertsCheckCommandTest extends TestCase
{
    use FakesDiscord;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeDiscord();
    }

    public function test_an_order_waiting_for_payment_warns_once_a_day_until_paid(): void
    {
        $order = Order::factory()->create(['created_at' => now()->subDays(4)]);

        $this->artisan('alerts:check')->assertSuccessful();
        $this->artisan('alerts:check')->assertSuccessful();

        $this->assertSame(["⏰ Há 4 dias à espera de pagamento — {$order->order_number}"], $this->alertTitles(AlertChannel::ORDERS));

        $this->travel(25)->hours();
        $this->artisan('alerts:check');
        $this->assertCount(2, $this->alertTitles(AlertChannel::ORDERS));

        // Pago: a marca apaga-se.
        $order->update(['status' => 'paid', 'payment_status' => 'paid']);
        $this->artisan('alerts:check');
        $this->assertDatabaseMissing('alert_states', ['key' => "stale-payment:{$order->id}"]);
    }

    public function test_a_recent_order_is_not_stale(): void
    {
        Order::factory()->create(['created_at' => now()->subDays(2)]);

        $this->artisan('alerts:check');

        $this->assertSame([], $this->alertTitles(AlertChannel::ORDERS));
    }

    public function test_production_time_counts_from_entering_production(): void
    {
        // Nasceu ha 10 dias, mas so entrou em producao ha 1.
        $fresh = Order::factory()->paid()->create(['status' => 'in_production', 'created_at' => now()->subDays(10)]);
        OrderStatusHistory::query()->forceCreate(['order_id' => $fresh->id, 'from_status' => 'paid', 'to_status' => 'in_production', 'created_at' => now()->subDay()]);

        $stuck = Order::factory()->paid()->create(['status' => 'in_production', 'created_at' => now()->subDays(10)]);
        OrderStatusHistory::query()->forceCreate(['order_id' => $stuck->id, 'from_status' => 'paid', 'to_status' => 'in_production', 'created_at' => now()->subDays(5)]);

        $this->artisan('alerts:check');

        $this->assertSame(["⏰ Há 5 dias em produção — {$stuck->order_number}"], $this->alertTitles(AlertChannel::STOCK));
    }

    public function test_spools_below_the_minimum_warn(): void
    {
        Material::factory()->create(['name' => 'PLA Preto', 'spools_in_stock' => 1, 'min_spools' => 3]);
        Material::factory()->create(['name' => 'PETG', 'spools_in_stock' => 1, 'min_spools' => 0]);

        $this->artisan('alerts:check');

        $this->assertSame(['🧵 Bobines a acabar — PLA Preto'], $this->alertTitles(AlertChannel::STOCK));
    }

    public function test_a_key_about_to_expire_warns_but_one_without_expiry_never_does(): void
    {
        app(ClientRepository::class)->createPersonalAccessGrantClient('Chaves de API do 12studio', 'users');
        $admin = User::factory()->admin()->create();
        app(ApiKeyService::class)->create($admin, 'Portátil', 'read', 7);
        app(ApiKeyService::class)->create($admin, 'Servidor', 'read', null);

        $this->travel(5)->days();
        $this->artisan('alerts:check');

        $this->assertAlerted(AlertChannel::SECURITY, 'Chave de API a expirar — Portátil');
        $this->assertNotAlerted(AlertChannel::SECURITY, 'a expirar — Servidor');
    }

    public function test_claude_reads_arrive_as_a_digest(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Rita']);
        $auditor = app(McpAuditor::class);

        // Primeira volta so marca o ponto de partida.
        $auditor->record($admin, 'products_list', McpActivity::RESULT_OK);
        $this->artisan('alerts:mcp-reads')->assertSuccessful();
        $this->assertSame([], $this->alertTitles(AlertChannel::SECURITY));

        $auditor->record($admin, 'products_list', McpActivity::RESULT_OK);
        $auditor->record($admin, 'products_list', McpActivity::RESULT_OK);
        $auditor->record($admin, 'order_get', McpActivity::RESULT_OK);

        $this->artisan('alerts:mcp-reads');

        $embed = $this->alertEmbed(AlertChannel::SECURITY, 'Claude consultou 3× nos últimos 15 min');
        $this->assertStringContainsString("products_list ×2\norder_get ×1", $embed['description']);

        // Nada de novo: nada a dizer.
        $this->artisan('alerts:mcp-reads');
        $this->assertCount(1, $this->alertTitles(AlertChannel::SECURITY));
    }
}
