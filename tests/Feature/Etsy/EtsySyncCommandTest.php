<?php

namespace Tests\Feature\Etsy;

use App\Alerts\AlertChannel;
use App\Jobs\SyncEtsyListing;
use App\Models\EtsyConnection;
use App\Models\EtsyListing;
use App\Models\Variant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Alerts\FakesDiscord;
use Tests\TestCase;

/**
 * `etsy:sync`: a rede por baixo dos ganchos. Apanha o que nao dispara nada
 * (um preco mudado, um produto arquivado) e, de noite, confere com a Etsy.
 */
class EtsySyncCommandTest extends TestCase
{
    use FakesDiscord;
    use FakesEtsy;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->configureEtsy();
        $this->connectEtsy();
        $this->fakeEtsyShopConfig();

        Queue::fake([SyncEtsyListing::class]);
    }

    /**
     * @return array<int, string>
     */
    private function writes(): array
    {
        return $this->etsyRequests()
            ->filter(fn (Request $request): bool => $request->method() !== 'GET')
            ->map(fn (Request $request): string => $request->method().' '.str_replace(self::ETSY_API, '', $request->url()))
            ->values()
            ->all();
    }

    public function test_it_sends_what_changed_and_nothing_else(): void
    {
        $this->etsyPushedProduct('active');

        $this->artisan('etsy:sync')->assertSuccessful();
        $this->assertSame([], $this->writes());

        Variant::query()->where('sku', 'VASO-P')->update(['price_cents' => 2990]);

        $this->artisan('etsy:sync')->assertSuccessful();

        $this->assertContains('PUT /listings/7100/inventory', $this->writes());
    }

    public function test_it_is_quiet_when_etsy_is_off_or_not_connected(): void
    {
        $this->etsyPushedProduct('active');
        Variant::query()->where('sku', 'VASO-P')->update(['price_cents' => 2990]);

        config(['etsy.enabled' => false]);
        $this->artisan('etsy:sync')->assertSuccessful();

        config(['etsy.enabled' => true]);
        // Ligacao partida: o aviso disso ja saiu; nao se tenta anuncio a anuncio.
        EtsyConnection::query()->update(['broken_at' => now()]);
        $this->artisan('etsy:sync')->assertSuccessful();

        $this->assertSame([], $this->writes());
    }

    public function test_a_listing_that_fails_does_not_stop_the_others_and_warns_once(): void
    {
        $this->etsyPushedProduct('active');
        $this->fakeDiscord();
        Variant::query()->where('sku', 'VASO-P')->update(['price_cents' => 2990]);
        $this->fakeEtsyListingWrites(['update' => Http::response(['error' => 'Title is invalid'], 400)]);

        $this->artisan('etsy:sync')->assertSuccessful();
        $this->artisan('etsy:sync')->assertSuccessful();

        $this->assertStringContainsString('Title is invalid', (string) EtsyListing::query()->sole()->last_error);
        // O mesmo erro de 15 em 15 minutos era ruido: avisa uma vez.
        $this->assertCount(1, array_filter($this->alertTitles(AlertChannel::SYSTEM), fn (string $title): bool => str_contains($title, 'Etsy')));
    }

    public function test_the_full_run_checks_the_states_with_etsy_first(): void
    {
        $this->etsyPushedProduct('active');
        Http::fake(['etsy.test/v3/application/listings/batch*' => Http::response(['count' => 1, 'results' => [
            $this->etsyListing(['listing_id' => 7100, 'state' => 'expired']),
        ]])]);

        $this->artisan('etsy:sync', ['--full' => true])->assertSuccessful();

        $this->assertSame('expired', EtsyListing::query()->sole()->state);
        // E um expirado ja nao e mexido: renovar paga-se.
        $this->assertSame([], $this->writes());
    }

    public function test_the_full_run_retries_the_listing_of_a_deleted_product(): void
    {
        $product = $this->etsyPushedProduct('active');
        // Apagado quando a Etsy estava em baixo: o anuncio ficou la.
        $product->deleteQuietly();
        EtsyListing::query()->update(['last_error' => 'A Etsy está com problemas (HTTP 503).']);
        Http::fake(['etsy.test/v3/application/listings/batch*' => Http::response(['count' => 1, 'results' => [
            $this->etsyListing(['listing_id' => 7100, 'state' => 'active']),
        ]])]);

        $this->artisan('etsy:sync', ['--full' => true])->assertSuccessful();

        $this->assertContains('DELETE /listings/7100', $this->writes());
        $this->assertNull(EtsyListing::query()->sole()->etsy_listing_id);
    }

    public function test_both_runs_are_on_the_schedule(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);

        $this->assertTrue($commands->contains(fn (string $command): bool => str_ends_with($command, 'etsy:sync')));
        $this->assertTrue($commands->contains(fn (string $command): bool => str_ends_with($command, 'etsy:sync --full')));
    }
}
