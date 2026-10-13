<?php

namespace Tests\Feature\Etsy;

use App\Alerts\AlertChannel;
use App\Etsy\EtsyException;
use App\Etsy\EtsyTokens;
use App\Models\EtsyConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Alerts\FakesDiscord;
use Tests\TestCase;

class EtsyTokensTest extends TestCase
{
    use FakesDiscord;
    use FakesEtsy;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureEtsy();
    }

    private function tokens(): EtsyTokens
    {
        return app(EtsyTokens::class);
    }

    public function test_the_tokens_are_encrypted_at_rest(): void
    {
        $this->connectEtsy();

        $row = DB::table('etsy_connections')->first();

        // Um backup da base de dados nao pode trazer a loja la dentro.
        $this->assertStringNotContainsString('acesso-valido', (string) $row->access_token);
        $this->assertStringNotContainsString('renovacao-valida', (string) $row->refresh_token);
    }

    public function test_a_valid_token_is_used_as_it_is(): void
    {
        $this->connectEtsy();
        Http::fake();

        $this->assertSame('9001.acesso-valido', $this->tokens()->accessToken());

        Http::assertNothingSent();
    }

    public function test_an_expired_token_is_renewed_and_both_new_tokens_are_kept(): void
    {
        $this->connectEtsy(['access_expires_at' => now()->subMinute()]);
        Http::fake(['etsy.test/v3/public/oauth/token' => Http::response([
            'access_token' => '9001.acesso-novo',
            'refresh_token' => '9001.renovacao-nova',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ])]);

        $this->freezeTime();

        $this->assertSame('9001.acesso-novo', $this->tokens()->accessToken());

        $request = $this->etsyRequests('/oauth/token')->sole();
        $this->assertSame('refresh_token', $request['grant_type']);
        $this->assertSame('chave-de-teste', $request['client_id']);
        $this->assertSame('9001.renovacao-valida', $request['refresh_token']);

        // O de renovacao tambem: se a Etsy o rodar e nos guardarmos o velho,
        // a ligacao morre daqui a uma hora sem ninguem perceber porque.
        $connection = EtsyConnection::query()->sole();
        $this->assertSame('9001.renovacao-nova', $connection->refresh_token);
        $this->assertSame(now()->addHour()->timestamp, $connection->access_expires_at->timestamp);
    }

    public function test_a_token_about_to_expire_is_renewed_ahead_of_time(): void
    {
        // Trinta segundos nao chegam para um upload de imagem.
        $this->connectEtsy(['access_expires_at' => now()->addSeconds(30)]);
        Http::fake(['etsy.test/v3/public/oauth/token' => Http::response([
            'access_token' => '9001.acesso-novo',
            'refresh_token' => '9001.renovacao-nova',
            'expires_in' => 3600,
        ])]);

        $this->assertSame('9001.acesso-novo', $this->tokens()->accessToken());
    }

    public function test_a_token_already_renewed_by_another_process_is_not_renewed_again(): void
    {
        $stale = $this->connectEtsy(['access_expires_at' => now()->subMinute()]);
        Http::fake();

        // O worker renova entre a primeira leitura deste processo e a vez
        // dele no cadeado. Encenado assim: logo a seguir ao primeiro SELECT a
        // tabela, a linha muda por baixo — como se outro processo a gravasse.
        $renewedElsewhere = false;

        DB::listen(function ($query) use (&$renewedElsewhere, $stale): void {
            if ($renewedElsewhere || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'etsy_connections')) {
                return;
            }

            $renewedElsewhere = true;

            DB::table('etsy_connections')->where('id', $stale->id)->update([
                'access_token' => encrypt('9001.acesso-do-outro', false),
                'access_expires_at' => now()->addHour(),
            ]);
        });

        // Quem chega a seguir tem de reler a linha, nao de gastar o token de
        // renovacao que ja foi trocado.

        $this->assertSame('9001.acesso-do-outro', $this->tokens()->accessToken());

        Http::assertNothingSent();
    }

    public function test_a_revoked_connection_says_so_and_warns_the_owner(): void
    {
        $this->fakeDiscord();
        $this->connectEtsy(['access_expires_at' => now()->subMinute()]);
        Http::fake(['etsy.test/v3/public/oauth/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Refresh token is invalid',
        ], 400)]);

        try {
            $this->tokens()->accessToken();
            $this->fail('Devia ter lancado.');
        } catch (EtsyException $exception) {
            $this->assertStringContainsString('Volta a ligar', $exception->getMessage());
        }

        $this->assertNotNull(EtsyConnection::query()->sole()->last_error);
        $this->assertAlerted(AlertChannel::SYSTEM, 'Etsy');
    }

    public function test_a_broken_connection_does_not_keep_hammering_etsy(): void
    {
        $this->fakeDiscord();
        $this->connectEtsy(['access_expires_at' => now()->subMinute(), 'broken_at' => now()]);
        Http::fake(['etsy.test/*' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            $this->tokens()->accessToken();
            $this->fail('Devia ter lancado.');
        } catch (EtsyException) {
            // Era de esperar.
        }

        // Partida e partida: de cinco em cinco minutos a importacao voltava a
        // tentar, e a avisar, ate alguem religar a loja.
        $this->assertCount(0, $this->etsyRequests('/oauth/token'));
    }

    public function test_forgetting_removes_the_connection(): void
    {
        $this->connectEtsy();

        $this->tokens()->forget();

        $this->assertSame(0, EtsyConnection::query()->count());
        $this->assertFalse($this->tokens()->isConnected());
    }
}
