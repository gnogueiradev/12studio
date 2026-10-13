<?php

namespace Tests\Feature\Etsy;

use App\Etsy\EtsyApiException;
use App\Etsy\EtsyClient;
use App\Etsy\EtsyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class EtsyClientTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureEtsy();
    }

    private function client(): EtsyClient
    {
        return app(EtsyClient::class);
    }

    public function test_every_request_carries_the_key_with_the_secret_and_the_token(): void
    {
        $this->connectEtsy();
        Http::fake(['etsy.test/v3/application/users/me' => Http::response(['user_id' => 9001, 'shop_id' => 4242])]);

        $this->assertSame(['user_id' => 9001, 'shop_id' => 4242], $this->client()->get('/users/me'));

        $request = $this->etsyRequests('/users/me')->sole();
        // A Etsy quer a chave e o segredo juntos no mesmo cabecalho.
        $this->assertSame('chave-de-teste:segredo-de-teste', $request->header('x-api-key')[0]);
        $this->assertSame('Bearer 9001.acesso-valido', $request->header('Authorization')[0]);
    }

    public function test_public_reads_go_without_a_token(): void
    {
        // Sem loja ligada: pesquisar anuncios e ler a taxonomia so pedem a chave.
        Http::fake(['etsy.test/v3/application/seller-taxonomy/nodes' => Http::response(['count' => 0, 'results' => []])]);

        $this->client()->get('/seller-taxonomy/nodes', public: true);

        $request = $this->etsyRequests('/seller-taxonomy')->sole();
        $this->assertSame('chave-de-teste:segredo-de-teste', $request->header('x-api-key')[0]);
        $this->assertFalse($request->hasHeader('Authorization'));
    }

    public function test_a_read_is_retried_when_etsy_asks_to_slow_down(): void
    {
        $this->connectEtsy();
        Http::fake(['etsy.test/*' => Http::sequence()
            ->push(['error' => 'Too many requests'], 429, ['retry-after' => '2'])
            ->push(['ok' => true])]);

        $this->assertSame(['ok' => true], $this->client()->get('/shops/4242'));

        $this->assertCount(2, $this->etsyRequests('/shops/4242'));
        // Espera o que a Etsy mandou esperar, nao um numero nosso.
        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
    }

    public function test_a_read_is_retried_when_etsy_is_down_or_unreachable(): void
    {
        $this->connectEtsy();
        Http::fake(['etsy.test/*' => Http::sequence()
            ->pushFailedConnection()
            ->push(['error' => 'oops'], 503)
            ->push(['ok' => true])]);

        $this->assertSame(['ok' => true], $this->client()->get('/shops/4242'));
        $this->assertCount(3, $this->etsyRequests('/shops/4242'));
    }

    public function test_a_write_is_never_repeated_on_its_own(): void
    {
        $this->connectEtsy();
        Http::fake(['etsy.test/*' => Http::sequence()
            ->push(['error' => 'oops'], 503)
            ->push(['listing_id' => 1])]);

        // Um POST que criou o anuncio e depois deu 503 criava outro a segunda.
        try {
            $this->client()->postForm('/shops/4242/listings', ['title' => 'Vaso']);
            $this->fail('Devia ter lancado.');
        } catch (EtsyApiException $exception) {
            $this->assertSame(503, $exception->status);
        }

        $this->assertCount(1, $this->etsyRequests('/listings'));
    }

    public function test_a_lost_connection_on_a_write_is_not_repeated_either(): void
    {
        $this->connectEtsy();
        $attempts = 0;
        Http::fake(function () use (&$attempts): never {
            $attempts++;

            throw new ConnectionException('cURL error 28');
        });

        try {
            $this->client()->postForm('/shops/4242/listings', ['title' => 'Vaso']);
            $this->fail('Devia ter lancado.');
        } catch (EtsyException $exception) {
            // Nao se sabe se o pedido chegou: diz-se isso, em vez de repetir.
            $this->assertStringContainsString('confirma antes de repetir', $exception->getMessage());
        }

        $this->assertSame(1, $attempts);
    }

    public function test_the_explanation_etsy_gives_reaches_whoever_called(): void
    {
        $this->connectEtsy();
        Http::fake(['etsy.test/*' => Http::response(['error' => 'Invalid taxonomy_id: 7'], 400)]);

        try {
            $this->client()->postForm('/shops/4242/listings', ['taxonomy_id' => 7]);
            $this->fail('Devia ter lancado.');
        } catch (EtsyApiException $exception) {
            // "Etsy API error (400)" nao diz a ninguem o que corrigir.
            $this->assertSame(400, $exception->status);
            $this->assertStringContainsString('Invalid taxonomy_id: 7', $exception->getMessage());
        }
    }

    public function test_each_kind_of_write_uses_its_own_encoding(): void
    {
        $this->connectEtsy();
        Http::fake(['etsy.test/*' => Http::response(['ok' => true])]);

        $this->client()->postForm('/shops/4242/listings', ['title' => 'Vaso', 'tags' => ['a', 'b'], 'is_supply' => false]);
        $this->client()->putJson('/listings/1/inventory', ['products' => [['sku' => 'VAS-1']]]);

        [$form, $json] = $this->etsyRequests()->all();

        $this->assertStringStartsWith('application/x-www-form-urlencoded', $form->header('Content-Type')[0]);
        // Listas vao separadas por virgulas e booleanos por extenso, como a Etsy os le.
        $this->assertSame('title=Vaso&tags=a%2Cb&is_supply=false', $form->body());

        $this->assertStringStartsWith('application/json', $json->header('Content-Type')[0]);
        $this->assertSame(['products' => [['sku' => 'VAS-1']]], $json->data());
    }

    public function test_the_rate_limits_etsy_reports_are_kept_for_the_status_page(): void
    {
        $this->connectEtsy();
        Http::fake(['etsy.test/*' => Http::response(['ok' => true], 200, [
            'x-limit-per-second' => '10',
            'x-remaining-this-second' => '9',
            'x-limit-per-day' => '10000',
            'x-remaining-today' => '9876',
        ])]);

        $this->client()->get('/shops/4242');

        $limits = Cache::get(EtsyClient::LIMITS_CACHE_KEY);
        $this->assertSame(10000, $limits['per_day']);
        $this->assertSame(9876, $limits['remaining_today']);
    }

    public function test_nothing_leaves_when_the_integration_is_switched_off(): void
    {
        $this->configureEtsy(enabled: false);
        $this->connectEtsy();
        Http::fake();

        try {
            $this->client()->get('/users/me');
            $this->fail('Devia ter lancado.');
        } catch (EtsyException $exception) {
            $this->assertStringContainsString('desligada', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_without_a_connected_shop_it_says_where_to_connect(): void
    {
        Http::fake();

        try {
            $this->client()->get('/users/me');
            $this->fail('Devia ter lancado.');
        } catch (EtsyException $exception) {
            $this->assertStringContainsString('Definições → Etsy', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
