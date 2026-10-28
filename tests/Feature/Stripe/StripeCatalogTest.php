<?php

namespace Tests\Feature\Stripe;

use App\Jobs\SyncStripeProduct;
use App\Models\Product;
use App\Models\Variant;
use App\Stripe\StripeCatalog;
use App\Stripe\StripeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O espelho do catalogo no Stripe: um Product por produto, um Price por
 * variante, e um Price novo sempre que o preco muda (no Stripe nao se edita).
 */
class StripeCatalogTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
    }

    /**
     * @return array{0: Product, 1: Variant, 2: Variant}
     */
    private function vase(): array
    {
        // Criados com o Stripe desligado: o observer nao agenda nada aqui.
        config(['stripe.enabled' => false]);

        $product = Product::factory()->create(['name' => 'Vaso', 'description' => '<p>Um <strong>vaso</strong> impresso.</p>']);
        $small = Variant::factory()->for($product)->create(['size_label' => 'P', 'price_cents' => 1500]);
        $large = Variant::factory()->for($product)->create(['size_label' => 'G', 'price_cents' => 2500]);

        config(['stripe.enabled' => true]);

        return [$product, $small, $large];
    }

    public function test_creates_one_product_and_one_price_per_variant(): void
    {
        [$product, $small, $large] = $this->vase();

        app(StripeCatalog::class)->sync($product);

        $product->refresh();
        $this->assertNotNull($product->stripe_product_id);

        $stripeProduct = $this->stripe['products'][$product->stripe_product_id];
        $this->assertSame('Vaso', $stripeProduct['name']);
        $this->assertSame('Um vaso impresso.', $stripeProduct['description']);
        $this->assertSame('true', $stripeProduct['active']);

        foreach ([[$small, 1500, 'P'], [$large, 2500, 'G']] as [$variant, $cents, $nickname]) {
            $variant->refresh();
            $price = $this->stripe['prices'][$variant->stripe_price_id];

            $this->assertSame((string) $cents, $price['unit_amount']);
            $this->assertSame($product->stripe_product_id, $price['product']);
            $this->assertSame($nickname, $price['nickname']);
            $this->assertSame($cents, $variant->stripe_price_cents);
        }
    }

    public function test_a_new_price_replaces_the_old_one_which_is_archived(): void
    {
        [$product, , $large] = $this->vase();
        app(StripeCatalog::class)->sync($product);
        $oldPrice = $large->refresh()->stripe_price_id;

        $large->forceFill(['price_cents' => 2700])->saveQuietly();
        app(StripeCatalog::class)->sync($product->refresh());

        $large->refresh();
        $this->assertNotSame($oldPrice, $large->stripe_price_id);
        $this->assertSame('2700', $this->stripe['prices'][$large->stripe_price_id]['unit_amount']);
        $this->assertSame('false', $this->stripe['prices'][$oldPrice]['active']);
        $this->assertSame(2700, $large->stripe_price_cents);
    }

    public function test_syncing_again_without_changes_creates_nothing(): void
    {
        [$product] = $this->vase();
        app(StripeCatalog::class)->sync($product);

        app(StripeCatalog::class)->sync($product->refresh());

        $this->assertCount(1, $this->stripeCallsTo('POST', 'products'));
        $this->assertCount(2, $this->stripeCallsTo('POST', 'prices'));
    }

    public function test_archiving_a_variant_archives_its_price(): void
    {
        [$product, $small] = $this->vase();
        app(StripeCatalog::class)->sync($product);

        $small->forceFill(['active' => false])->saveQuietly();
        app(StripeCatalog::class)->sync($product->refresh());

        $this->assertSame('false', $this->stripe['prices'][$small->refresh()->stripe_price_id]['active']);
    }

    public function test_an_archived_or_deleted_product_is_inactive_in_stripe(): void
    {
        [$product] = $this->vase();
        app(StripeCatalog::class)->sync($product);

        $product->forceFill(['status' => 'archived'])->saveQuietly();
        app(StripeCatalog::class)->sync($product->refresh());

        $this->assertSame('false', $this->stripe['products'][$product->stripe_product_id]['active']);

        foreach ($product->variants as $variant) {
            $this->assertSame('false', $this->stripe['prices'][$variant->stripe_price_id]['active']);
        }
    }

    public function test_creation_carries_an_idempotency_key(): void
    {
        [$product] = $this->vase();

        app(StripeCatalog::class)->sync($product);

        $this->assertSame("12studio-product-{$product->id}", $this->stripeCallsTo('POST', 'products')[0]['idempotency']);
        $this->assertNotNull($this->stripeCallsTo('POST', 'prices')[0]['idempotency']);
    }

    public function test_changing_what_stripe_shows_schedules_a_sync(): void
    {
        Queue::fake();
        [$product, $small] = $this->vase();

        $small->update(['price_cents' => 1600]);

        Queue::assertPushed(SyncStripeProduct::class, fn (SyncStripeProduct $job): bool => $job->productId === $product->id);
    }

    public function test_changing_only_the_stock_schedules_nothing(): void
    {
        Queue::fake();
        [, $small] = $this->vase();

        $small->update(['stock' => 3, 'reserved_stock' => 1]);

        Queue::assertNotPushed(SyncStripeProduct::class);
    }

    public function test_with_stripe_disabled_nothing_is_scheduled_nor_sent(): void
    {
        Queue::fake();
        config(['stripe.enabled' => false]);

        Product::factory()->create();

        Queue::assertNotPushed(SyncStripeProduct::class);

        $this->expectException(StripeException::class);
        app(StripeCatalog::class)->sync(Product::factory()->create());
    }

    public function test_the_command_syncs_every_product(): void
    {
        [$product] = $this->vase();

        $this->artisan('stripe:sync-products')->assertSuccessful();

        $this->assertNotNull($product->refresh()->stripe_product_id);
    }

    public function test_a_reactivated_variant_gets_a_new_active_price(): void
    {
        [$product, $small] = $this->vase();
        app(StripeCatalog::class)->sync($product);
        $first = $small->refresh()->stripe_price_id;

        $small->forceFill(['active' => false])->saveQuietly();
        app(StripeCatalog::class)->sync($product->refresh());
        $small->forceFill(['active' => true])->saveQuietly();
        app(StripeCatalog::class)->sync($product->refresh());

        $small->refresh();
        $this->assertNotSame($first, $small->stripe_price_id);
        $this->assertSame('true', $this->stripe['prices'][$small->stripe_price_id]['active']);
        $this->assertSame(1500, $small->stripe_price_cents);
    }

    public function test_a_sync_without_changes_touches_no_price(): void
    {
        [$product] = $this->vase();
        app(StripeCatalog::class)->sync($product);
        $before = count($this->stripeCallsTo('POST', 'prices*'));

        app(StripeCatalog::class)->sync($product->refresh());

        $this->assertSame($before, count($this->stripeCallsTo('POST', 'prices*')));
    }
}
