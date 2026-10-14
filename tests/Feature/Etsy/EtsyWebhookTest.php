<?php

namespace Tests\Feature\Etsy;

use App\Jobs\ImportEtsyOrders;
use App\Jobs\SyncEtsyListing;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * O aviso que a Etsy manda quando ha uma venda. E uma porta aberta para a
 * internet, sem sessao nem password: o que a guarda e a assinatura. Os testes
 * sao sobretudo sobre quem NAO entra — e sobre o aviso nao fazer mais do que
 * mandar correr a importacao de sempre.
 */
class EtsyWebhookTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    /**
     * Como a Etsy o da no portal: `whsec_` e a chave em base64. Montado aqui e
     * nao escrito por extenso, para o secret scanning do GitHub nao o tomar
     * por um segredo da Stripe (usam o mesmo prefixo).
     */
    private static function secret(): string
    {
        return 'whsec_'.base64_encode('segredo-de-teste-do-webhook-123');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Mail::fake();

        $this->configureEtsy();
        config(['etsy.webhook_secret' => self::secret()]);
        $this->connectEtsy(['orders_since' => now()->subHour()]);
        $this->fakeEtsyShopConfig();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function body(array $overrides = []): string
    {
        return (string) json_encode([
            'event_type' => 'order.paid',
            'resource_url' => 'https://api.etsy.com/v3/application/shops/4242/receipts/3001',
            'shop_id' => '4242',
            ...$overrides,
        ]);
    }

    private function signature(string $body, string $id, int $timestamp, ?string $secret = null): string
    {
        $secret ??= self::secret();
        $key = (string) base64_decode(substr($secret, strlen('whsec_')), true);

        return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }

    /**
     * Um aviso como a Etsy o manda: o corpo em bruto e os tres cabecalhos.
     *
     * @param  array<string, string|null>  $headers  Para trocar ou tirar (null) um cabecalho.
     */
    private function deliver(?string $body = null, array $headers = [], string $id = 'msg_1'): TestResponse
    {
        $body ??= $this->body();
        $timestamp = now()->timestamp;

        $all = array_filter([
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => $this->signature($body, $id, $timestamp),
            ...$headers,
        ], fn (?string $value): bool => $value !== null);

        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($all as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', route('webhooks.etsy'), [], [], [], $server, $body);
    }

    /**
     * Faz de conta que a importacao que estava na fila ja comecou: e ai que
     * deixa de ser "a unica", e um aviso novo pode agendar outra.
     */
    private function importStarted(): void
    {
        Cache::lock('laravel_unique_job:'.ImportEtsyOrders::class.':')->forceRelease();
    }

    // ── Entra ──────────────────────────────────────────────────────────────

    public function test_a_signed_sale_notice_asks_for_an_import(): void
    {
        Queue::fake([ImportEtsyOrders::class]);

        $this->deliver()->assertNoContent();

        Queue::assertPushed(ImportEtsyOrders::class, 1);
    }

    public function test_from_the_notice_to_the_order(): void
    {
        $this->etsyPushedProduct('active');
        Queue::fake([SyncEtsyListing::class]);
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        // Com a fila dos testes a importacao corre ja. A encomenda vem do que
        // a Etsy responde a NOS — do aviso so se aproveita o "ha novidades".
        $this->deliver()->assertNoContent();

        $this->assertSame('3001', Order::query()->where('sales_channel', 'etsy')->sole()->external_order_reference);
    }

    public function test_every_order_event_asks_for_an_import(): void
    {
        foreach (['order.paid', 'order.canceled', 'order.shipped', 'order.delivered'] as $index => $event) {
            Queue::fake([ImportEtsyOrders::class]);
            $this->importStarted();

            $this->deliver($this->body(['event_type' => $event]), id: "msg_{$index}")->assertNoContent();

            Queue::assertPushed(ImportEtsyOrders::class, 1);
        }
    }

    public function test_notices_in_a_row_are_one_import(): void
    {
        Queue::fake([ImportEtsyOrders::class]);

        // Paga, enviada e entregue de seguida: a importacao que ja esta na
        // fila traz tudo o que mudou, nao sao precisas tres.
        foreach (['order.paid', 'order.shipped', 'order.delivered'] as $index => $event) {
            $this->deliver($this->body(['event_type' => $event]), id: "msg_{$index}")->assertNoContent();
        }

        Queue::assertPushed(ImportEtsyOrders::class, 1);
    }

    public function test_one_valid_signature_among_several_is_enough(): void
    {
        Queue::fake([ImportEtsyOrders::class]);
        $body = $this->body();
        $valid = $this->signature($body, 'msg_1', now()->timestamp);

        // A Etsy pode mandar mais de uma (quando troca de segredo).
        $this->deliver($body, ['webhook-signature' => 'v1,'.base64_encode('outra-assinatura').' '.$valid])->assertNoContent();

        Queue::assertPushed(ImportEtsyOrders::class, 1);
    }

    public function test_the_same_notice_delivered_twice_counts_once(): void
    {
        Queue::fake([ImportEtsyOrders::class]);

        // A Etsy repete o aviso (com o mesmo id) quando acha que nao chegou.
        // Mesmo com a primeira importacao ja feita, a repeticao nao pede outra.
        $this->deliver()->assertNoContent();
        $this->importStarted();
        $this->deliver()->assertNoContent();

        Queue::assertPushed(ImportEtsyOrders::class, 1);
    }

    // ── Entra, mas nao e connosco ──────────────────────────────────────────

    public function test_notices_about_listings_are_acknowledged_and_ignored(): void
    {
        Queue::fake([ImportEtsyOrders::class]);

        $this->deliver($this->body(['event_type' => 'listing.updated', 'listing_id' => '7100']))->assertNoContent();

        Queue::assertNothingPushed();
    }

    public function test_a_notice_about_another_shop_is_acknowledged_and_ignored(): void
    {
        Queue::fake([ImportEtsyOrders::class]);

        $this->deliver($this->body(['shop_id' => '9999']))->assertNoContent();

        Queue::assertNothingPushed();
    }

    public function test_a_signed_body_that_is_not_json_is_acknowledged_and_ignored(): void
    {
        Queue::fake([ImportEtsyOrders::class]);

        $this->deliver('isto nao e json')->assertNoContent();

        Queue::assertNothingPushed();
    }

    // ── Nao entra ──────────────────────────────────────────────────────────

    public function test_a_wrong_signature_is_refused(): void
    {
        Queue::fake([ImportEtsyOrders::class]);
        $body = $this->body();

        $this->deliver($body, ['webhook-signature' => $this->signature($body, 'msg_1', now()->timestamp, 'whsec_'.base64_encode('outro-segredo'))])
            ->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_a_body_changed_on_the_way_is_refused(): void
    {
        Queue::fake([ImportEtsyOrders::class]);
        $signed = $this->body();

        $this->deliver($this->body(['shop_id' => '4242', 'event_type' => 'order.canceled']), [
            'webhook-signature' => $this->signature($signed, 'msg_1', now()->timestamp),
        ])->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_an_old_notice_is_refused(): void
    {
        Queue::fake([ImportEtsyOrders::class]);
        $body = $this->body();
        $old = now()->subMinutes(6)->timestamp;

        // Assinado a serio, mas ha seis minutos: e o que impede alguem de
        // guardar um aviso verdadeiro e reenvia-lo mais tarde.
        $this->deliver($body, [
            'webhook-timestamp' => (string) $old,
            'webhook-signature' => $this->signature($body, 'msg_1', $old),
        ])->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_a_notice_without_its_headers_is_refused(): void
    {
        Queue::fake([ImportEtsyOrders::class]);

        foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $header) {
            $this->deliver(headers: [$header => null])->assertUnauthorized();
        }

        $this->deliver(headers: ['webhook-timestamp' => 'ontem'])->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_without_a_secret_or_with_the_switch_off_the_door_does_not_exist(): void
    {
        Queue::fake([ImportEtsyOrders::class]);

        config(['etsy.webhook_secret' => null]);
        $this->deliver()->assertNotFound();

        config(['etsy.webhook_secret' => self::secret(), 'etsy.enabled' => false]);
        $this->deliver()->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_it_only_answers_to_post(): void
    {
        $this->get(route('webhooks.etsy'))->assertMethodNotAllowed();
    }

    public function test_the_door_is_outside_the_browser_session_and_has_a_ceiling(): void
    {
        $middleware = Route::getRoutes()->getByName('webhooks.etsy')?->gatherMiddleware() ?? [];

        // Sem o grupo `web`: nao ha sessao, nem cookies, nem CSRF — a Etsy
        // nao tem como mandar um token. O que a guarda e a assinatura.
        $this->assertNotContains('web', $middleware);
        $this->assertContains('throttle:etsy-webhook', $middleware);
    }

    // ── O ecra ─────────────────────────────────────────────────────────────

    public function test_the_settings_page_says_where_to_point_etsy(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.etsy.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('webhook.url', route('webhooks.etsy'))
                ->where('webhook.configured', true)
                ->where('webhook.events', ['order.paid', 'order.canceled', 'order.shipped', 'order.delivered']));
    }
}
