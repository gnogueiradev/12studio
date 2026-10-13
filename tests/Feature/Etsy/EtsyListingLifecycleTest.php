<?php

namespace Tests\Feature\Etsy;

use App\Etsy\EtsyException;
use App\Etsy\EtsyListingLifecycle;
use App\Etsy\EtsyListingSync;
use App\Jobs\SyncEtsyListing;
use App\Models\EtsyListing;
use App\Models\EtsyVariant;
use App\Models\Product;
use App\Models\Variant;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A vida de um anuncio depois de enviado: publicar, desativar, apagar, e
 * acompanhar sozinho o stock e o produto. O que os testes guardam, acima de
 * tudo, e o que NAO pode acontecer sem ninguem pedir: publicar, reativar um
 * anuncio que o dono desligou, mexer num anuncio feito a mao.
 */
class EtsyListingLifecycleTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->configureEtsy();
        $this->connectEtsy();
        $this->fakeEtsyShopConfig();

        // Mexer no stock agenda uma sincronizacao; aqui chama-se a mao. As
        // outras tarefas (fotografias) correm como sempre.
        Queue::fake([SyncEtsyListing::class]);
    }

    private function lifecycle(): EtsyListingLifecycle
    {
        return app(EtsyListingLifecycle::class);
    }

    private function listing(): EtsyListing
    {
        return EtsyListing::query()->sole();
    }

    /**
     * @return array<int, string> "PATCH /shops/4242/listings/7100", por ordem.
     */
    private function writes(): array
    {
        return $this->etsyRequests()
            ->filter(fn (Request $request): bool => $request->method() !== 'GET')
            ->map(fn (Request $request): string => $request->method().' '.str_replace(self::ETSY_API, '', $request->url()))
            ->values()
            ->all();
    }

    private function lastWrite(string $method): Request
    {
        return $this->etsyRequests()->filter(fn (Request $request): bool => $request->method() === $method)->last();
    }

    private function setStock(Product $product, int $stock): void
    {
        Variant::query()->where('product_id', $product->id)->update(['stock' => $stock]);
    }

    // ── Publicar ───────────────────────────────────────────────────────────

    public function test_publishing_sets_the_listing_on_sale(): void
    {
        $product = $this->etsyPushedProduct('draft');

        $listing = $this->lifecycle()->publish($product);

        $this->assertSame(['PATCH /shops/4242/listings/7100'], $this->writes());
        $this->assertSame('active', $this->lastWrite('PATCH')['state']);
        $this->assertSame('active', $listing->state);
        $this->assertNull($listing->auto_deactivated_at);
    }

    public function test_a_product_that_was_never_sent_cannot_be_published(): void
    {
        $product = $this->etsyReadyProduct();
        $this->fakeEtsyListingWrites();

        $this->expectException(EtsyException::class);
        $this->expectExceptionMessage('ainda não tem anúncio');

        $this->lifecycle()->publish($product);
    }

    public function test_what_changed_since_the_last_push_has_to_go_first(): void
    {
        $product = $this->etsyPushedProduct('draft');
        $product->update(['name' => 'Vaso Espiral XL']);

        try {
            $this->lifecycle()->publish($product->fresh());
            $this->fail('Devia ter lancado.');
        } catch (EtsyException $exception) {
            // Publicar o que nao e o que se viu na pre-visualizacao era
            // por a venda uma coisa que ninguem conferiu.
            $this->assertStringContainsString('por enviar', $exception->getMessage());
        }

        $this->assertSame([], $this->writes());
        $this->assertSame('draft', $this->listing()->state);
    }

    public function test_a_listing_without_a_photo_on_etsy_cannot_be_published(): void
    {
        $product = $this->etsyPushedProduct('draft');
        $this->listing()->images()->update(['etsy_image_id' => null]);

        try {
            $this->lifecycle()->publish($product);
            $this->fail('Devia ter lancado.');
        } catch (EtsyException $exception) {
            $this->assertStringContainsString('fotografia', $exception->getMessage());
        }

        $this->assertSame([], $this->writes());
    }

    public function test_publishing_what_is_already_on_sale_does_nothing(): void
    {
        $product = $this->etsyPushedProduct('active');

        $this->lifecycle()->publish($product);

        $this->assertSame([], $this->writes());
    }

    // ── Desativar ──────────────────────────────────────────────────────────

    public function test_deactivating_takes_the_listing_off_sale_for_good(): void
    {
        $product = $this->etsyPushedProduct('active');

        $listing = $this->lifecycle()->deactivate($product);

        $this->assertSame('inactive', $this->lastWrite('PATCH')['state']);
        $this->assertSame('inactive', $listing->state);
        // Desligado a mao: nao e a sincronizacao que o volta a ligar.
        $this->assertNull($listing->auto_deactivated_at);
    }

    public function test_only_a_listing_on_sale_can_be_deactivated(): void
    {
        $product = $this->etsyPushedProduct('draft');

        $this->expectException(EtsyException::class);

        $this->lifecycle()->deactivate($product);
    }

    // ── Apagar ─────────────────────────────────────────────────────────────

    public function test_deleting_removes_it_from_etsy_and_keeps_the_content_here(): void
    {
        $product = $this->etsyPushedProduct('active');
        $this->listing()->update(['title' => 'Spiral Vase']);

        $this->lifecycle()->delete($product);

        $this->assertSame(['DELETE /listings/7100'], $this->writes());

        $listing = $this->listing();
        $this->assertNull($listing->etsy_listing_id);
        $this->assertSame('deleted', $listing->state);
        $this->assertNull($listing->last_pushed_at);
        $this->assertNull($listing->last_push_hash);
        // O texto trabalhado fica: um anuncio novo parte dele.
        $this->assertSame('Spiral Vase', $listing->title);
        $this->assertSame(0, EtsyVariant::query()->whereNotNull('etsy_product_id')->count());
        $this->assertSame(0, $listing->images()->whereNotNull('etsy_image_id')->count());
    }

    public function test_a_listing_already_gone_from_etsy_counts_as_deleted(): void
    {
        $product = $this->etsyPushedProduct('draft');
        $this->fakeEtsyListingWrites(['delete' => Http::response(['error' => 'Listing not found'], 404)]);

        $this->lifecycle()->delete($product);

        $this->assertNull($this->listing()->etsy_listing_id);
    }

    public function test_when_etsy_refuses_to_delete_the_listing_is_at_least_taken_off_sale(): void
    {
        $product = $this->etsyPushedProduct('active');
        $this->fakeEtsyListingWrites(['delete' => Http::response(['error' => 'Listing cannot be deleted in this state'], 409)]);

        try {
            $this->lifecycle()->delete($product);
            $this->fail('Devia ter lancado.');
        } catch (EtsyException $exception) {
            $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
            $this->assertStringContainsString('desativado', $exception->getMessage());
        }

        $this->assertSame(['DELETE /listings/7100', 'PATCH /shops/4242/listings/7100'], $this->writes());
        $listing = $this->listing();
        $this->assertSame(7100, $listing->etsy_listing_id);
        $this->assertSame('inactive', $listing->state);
    }

    public function test_the_listing_of_a_deleted_product_can_still_be_deleted(): void
    {
        $product = $this->etsyPushedProduct('active');
        $product->delete();

        $this->lifecycle()->delete($product);

        $this->assertSame(['DELETE /listings/7100'], $this->writes());
    }

    public function test_after_deleting_a_new_push_creates_a_new_draft(): void
    {
        $product = $this->etsyPushedProduct('active');
        $this->lifecycle()->delete($product);
        $this->fakeEtsyListingWrites();

        $listing = app(EtsyListingSync::class)->push($product->fresh());

        $this->assertContains('POST /shops/4242/listings', $this->writes());
        $this->assertSame('draft', $listing->state);
    }

    // ── Acompanhar sozinho ─────────────────────────────────────────────────

    public function test_with_nothing_changed_the_sync_sends_nothing(): void
    {
        $this->etsyPushedProduct('active');

        $this->assertSame(EtsyListingLifecycle::UNCHANGED, $this->lifecycle()->sync($this->listing()));
        $this->assertSame([], $this->writes());
    }

    public function test_a_stock_change_reaches_etsy(): void
    {
        $product = $this->etsyPushedProduct('active');
        app(StockService::class)->decrement(Variant::query()->where('sku', 'VASO-P')->sole(), 2, 'manual_adjust');

        $this->assertSame(EtsyListingLifecycle::PUSHED, $this->lifecycle()->sync($this->listing()));

        $inventory = $this->lastWrite('PUT');
        $this->assertStringEndsWith('/listings/7100/inventory', $inventory->url());
        $this->assertSame(3, $inventory['products'][0]['offerings'][0]['quantity']);
        $this->assertSame('active', $this->listing()->state);

        // E a seguir ja nao ha nada para mandar.
        $this->fakeEtsyListingWrites();
        $this->assertSame(EtsyListingLifecycle::UNCHANGED, $this->lifecycle()->sync($this->listing()));
        $this->assertSame([], $this->writes());
    }

    public function test_a_price_change_reaches_etsy_too(): void
    {
        $this->etsyPushedProduct('active');
        Variant::query()->where('sku', 'VASO-P')->update(['price_cents' => 2990]);

        $this->lifecycle()->sync($this->listing());

        $this->assertSame(29.9, $this->lastWrite('PUT')['products'][0]['offerings'][0]['price']);
    }

    public function test_out_of_stock_takes_the_listing_off_sale_and_remembers_why(): void
    {
        $product = $this->etsyPushedProduct('active');
        $this->setStock($product, 0);
        $this->freezeTime();

        $this->assertSame(EtsyListingLifecycle::DEACTIVATED, $this->lifecycle()->sync($this->listing()));

        // So muda o estado: um inventario a zero a Etsy nao aceitava.
        $this->assertSame(['PATCH /shops/4242/listings/7100'], $this->writes());
        $this->assertSame('inactive', $this->lastWrite('PATCH')['state']);
        $listing = $this->listing();
        $this->assertSame('inactive', $listing->state);
        $this->assertSame(now()->timestamp, $listing->auto_deactivated_at?->timestamp);
    }

    public function test_when_stock_comes_back_it_goes_on_sale_again_by_itself(): void
    {
        $product = $this->etsyPushedProduct('active');
        $this->setStock($product, 0);
        $this->lifecycle()->sync($this->listing());

        $this->setStock($product, 4);
        $this->fakeEtsyListingWrites();

        $this->assertSame(EtsyListingLifecycle::REACTIVATED, $this->lifecycle()->sync($this->listing()));

        // Primeiro o inventario certo, so depois volta a estar a venda.
        $writes = $this->writes();
        $this->assertSame('PUT /listings/7100/inventory', $writes[1]);
        $this->assertSame('PATCH /shops/4242/listings/7100', end($writes));
        $this->assertSame('active', $this->lastWrite('PATCH')['state']);
        $listing = $this->listing();
        $this->assertSame('active', $listing->state);
        $this->assertNull($listing->auto_deactivated_at);
    }

    public function test_a_listing_the_owner_turned_off_is_never_turned_on_by_the_sync(): void
    {
        $product = $this->etsyPushedProduct('active');
        $this->lifecycle()->deactivate($product);
        app(StockService::class)->increment(Variant::query()->where('sku', 'VASO-P')->sole(), 5, 'manual_adjust');
        $this->fakeEtsyListingWrites();

        $this->assertSame(EtsyListingLifecycle::SKIPPED, $this->lifecycle()->sync($this->listing()));

        $this->assertSame([], $this->writes());
        $this->assertSame('inactive', $this->listing()->state);
    }

    public function test_an_archived_product_leaves_the_sale(): void
    {
        $product = $this->etsyPushedProduct('active');
        $product->update(['status' => 'archived']);

        $this->assertSame(EtsyListingLifecycle::DEACTIVATED, $this->lifecycle()->sync($this->listing()));

        $this->assertSame('inactive', $this->listing()->state);
        $this->assertNotNull($this->listing()->auto_deactivated_at);
    }

    public function test_a_draft_follows_the_product_but_is_never_published(): void
    {
        $product = $this->etsyPushedProduct('draft');
        $product->update(['name' => 'Vaso Espiral XL']);

        $this->assertSame(EtsyListingLifecycle::PUSHED, $this->lifecycle()->sync($this->listing()));

        $this->assertSame('Vaso Espiral XL', $this->etsyRequests()->first(fn (Request $r): bool => $r->method() === 'PATCH')['title']);
        $this->assertSame('draft', $this->listing()->state);
        $this->assertNotContains('active', $this->etsyRequests()->map(fn (Request $r) => $r->data()['state'] ?? null)->all());
    }

    public function test_listings_the_sync_must_leave_alone(): void
    {
        $product = $this->etsyPushedProduct('active');
        $product->update(['name' => 'Outro nome']);

        $cases = [
            'com a sincronizacao desligada' => ['sync_enabled' => false],
            // Feito a mao na Etsy e nunca enviado daqui: so com um envio pedido.
            'ligado e nunca enviado' => ['last_pushed_at' => null],
            // Repor um esgotado ou um expirado e renovar, e renovar paga-se.
            'esgotado' => ['state' => 'sold_out'],
            'expirado' => ['state' => 'expired'],
        ];

        foreach ($cases as $name => $attributes) {
            $this->listing()->update(['sync_enabled' => true, 'last_pushed_at' => now(), 'state' => 'active', ...$attributes]);
            $this->fakeEtsyListingWrites();

            $this->assertSame(EtsyListingLifecycle::SKIPPED, $this->lifecycle()->sync($this->listing()), $name);
            $this->assertSame([], $this->writes(), $name);
        }
    }

    public function test_a_sync_etsy_refuses_is_written_down_instead_of_thrown(): void
    {
        $product = $this->etsyPushedProduct('active');
        $product->update(['name' => 'Vaso Espiral XL']);
        $this->fakeEtsyListingWrites(['update' => Http::response(['error' => 'Title is invalid'], 400)]);

        $this->assertSame(EtsyListingLifecycle::FAILED, $this->lifecycle()->sync($this->listing()));

        $this->assertStringContainsString('Title is invalid', (string) $this->listing()->last_error);
    }

    // ── Conferir com a Etsy ────────────────────────────────────────────────

    public function test_the_state_etsy_has_is_brought_here(): void
    {
        $this->etsyPushedProduct('active');
        $this->listing()->update(['auto_deactivated_at' => null]);
        Http::fake(['etsy.test/v3/application/listings/batch*' => Http::response(['count' => 1, 'results' => [
            $this->etsyListing(['listing_id' => 7100, 'state' => 'sold_out', 'url' => 'https://www.etsy.com/listing/7100/vaso']),
        ]])]);
        $this->freezeTime();

        $result = $this->lifecycle()->refreshRemote();

        $this->assertSame(['checked' => 1, 'changed' => 1, 'gone' => 0], $result);
        $this->assertStringContainsString('listing_ids=7100', urldecode($this->etsyRequests('/listings/batch')->sole()->url()));
        $listing = $this->listing();
        $this->assertSame('sold_out', $listing->state);
        $this->assertSame('https://www.etsy.com/listing/7100/vaso', $listing->url);
        $this->assertSame(now()->timestamp, $listing->remote_checked_at?->timestamp);
    }

    public function test_a_listing_deleted_by_hand_on_etsy_is_noticed(): void
    {
        $this->etsyPushedProduct('active');
        Http::fake([
            'etsy.test/v3/application/listings/batch*' => Http::response(['count' => 0, 'results' => []]),
            // Nao veio no lote: pergunta-se por ele antes de o dar como perdido.
            'etsy.test/v3/application/listings/7100*' => Http::response(['error' => 'Listing not found'], 404),
        ]);

        $result = $this->lifecycle()->refreshRemote();

        $this->assertSame(1, $result['gone']);
        $listing = $this->listing();
        $this->assertNull($listing->etsy_listing_id);
        $this->assertSame('deleted', $listing->state);
    }

    public function test_a_listing_missing_from_the_batch_but_still_there_is_kept(): void
    {
        $this->etsyPushedProduct('active');
        Http::fake([
            'etsy.test/v3/application/listings/batch*' => Http::response(['count' => 0, 'results' => []]),
            'etsy.test/v3/application/listings/7100*' => Http::response($this->etsyListing(['listing_id' => 7100, 'state' => 'inactive'])),
        ]);

        $result = $this->lifecycle()->refreshRemote();

        $this->assertSame(0, $result['gone']);
        $this->assertSame(7100, $this->listing()->etsy_listing_id);
        $this->assertSame('inactive', $this->listing()->state);
    }
}
