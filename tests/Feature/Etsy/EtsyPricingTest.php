<?php

namespace Tests\Feature\Etsy;

use App\Etsy\EtsyMargin;
use App\Etsy\EtsyPricePolicy;
use App\Etsy\EtsySettings;
use App\Models\EtsyListing;
use App\Models\EtsyVariant;
use App\Models\Material;
use App\Models\User;
use App\Models\Variant;
use App\Services\PricingPreview;
use App\Support\Micros;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Que preco vai para a Etsy, e o que sobra dele. O dono ainda nao decidiu a
 * politica de preco: isto e o que lhe da os numeros para decidir.
 */
class EtsyPricingTest extends TestCase
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

    private function variant(string $sku = 'VASO-P'): Variant
    {
        return Variant::query()->where('sku', $sku)->sole();
    }

    // ── O preco ────────────────────────────────────────────────────────────

    public function test_by_default_etsy_gets_the_price_of_the_site(): void
    {
        $this->etsyReadyProduct();
        $policy = app(EtsyPricePolicy::class);

        $this->assertSame(2490, $policy->priceCents($this->variant()));
        $this->assertSame('site', $policy->source($this->variant()));
    }

    public function test_the_global_markup_is_added_and_rounded_to_the_cent(): void
    {
        $this->etsyReadyProduct();
        app(EtsySettings::class)->save(['markup_bp' => 1250]);
        $policy = app(EtsyPricePolicy::class);

        // 24,90 x 1,125 = 28,0125.
        $this->assertSame(2801, $policy->priceCents($this->variant()));
        $this->assertSame('markup', $policy->source($this->variant()));
    }

    public function test_an_own_price_beats_everything(): void
    {
        $product = $this->etsyReadyProduct();
        app(EtsySettings::class)->save(['markup_bp' => 1250]);
        $listing = EtsyListing::query()->create(['product_id' => $product->id]);
        EtsyVariant::query()->create(['variant_id' => $this->variant()->id, 'listing_id' => $listing->id, 'etsy_price_cents' => 3000]);
        $policy = app(EtsyPricePolicy::class);

        $this->assertSame(3000, $policy->priceCents($this->variant()->fresh()));
        $this->assertSame('own', $policy->source($this->variant()->fresh()));
    }

    // ── A margem ───────────────────────────────────────────────────────────

    public function test_the_margin_takes_vat_and_the_fees_of_etsy_out(): void
    {
        $this->etsyReadyProduct();
        app(EtsySettings::class)->save(['fee_percentage_bp' => 1000, 'fee_fixed_cents' => 50]);

        $margin = app(EtsyMargin::class)->forPrice($this->variant(), 2490);

        // 24,90 com 23 % de IVA sao 20,24 sem ele. As taxas incidem sobre o
        // que o comprador paga: 10 % de 24,90 + 0,50.
        $this->assertSame(2490, $margin['price_cents']);
        $this->assertSame(2024, $margin['ex_vat_cents']);
        $this->assertSame(299, $margin['fees_cents']);
        $this->assertSame(1725, $margin['net_cents']);
        // Sem peso, tempo e material nao ha custo — e nao se inventa um.
        $this->assertNull($margin['cost_cents']);
        $this->assertNull($margin['profit_cents']);
    }

    public function test_with_production_data_the_profit_is_what_is_left_after_the_cost(): void
    {
        $this->etsyReadyProduct();
        app(EtsySettings::class)->save(['fee_percentage_bp' => 1000, 'fee_fixed_cents' => 50]);
        $variant = $this->variant();
        $variant->update([
            'filament_weight_grams' => 120,
            'printing_time_minutes' => 240,
            'material_id' => Material::factory()->create(['price_per_kg_cents' => 2500])->id,
        ]);
        $variant = $variant->fresh();

        $cost = Micros::toCents(app(PricingPreview::class)->forVariant($variant)->productionCostMicros);
        $margin = app(EtsyMargin::class)->forPrice($variant, 2490);

        $this->assertGreaterThan(0, $cost);
        $this->assertSame($cost, $margin['cost_cents']);
        $this->assertSame(1725 - $cost, $margin['profit_cents']);
        $this->assertSame(intdiv((1725 - $cost) * 10_000, 2024), $margin['margin_bp']);
    }

    public function test_the_site_side_of_the_comparison_has_no_etsy_fees(): void
    {
        $this->etsyReadyProduct();
        app(EtsySettings::class)->save(['fee_percentage_bp' => 1000, 'fee_fixed_cents' => 50, 'markup_bp' => 1000]);

        $row = app(EtsyMargin::class)->compare($this->variant());

        $this->assertSame(2490, $row['site']['price_cents']);
        $this->assertSame(0, $row['site']['fees_cents']);
        $this->assertSame(2739, $row['etsy']['price_cents']);
        $this->assertSame(324, $row['etsy']['fees_cents']);
        $this->assertSame('markup', $row['price_source']);
    }

    // ── O ecra ─────────────────────────────────────────────────────────────

    public function test_the_page_lists_every_variant_with_both_sides(): void
    {
        $this->etsyReadyProduct();

        $this->actingAs($this->admin)
            ->get(route('admin.etsy.precos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/etsy/precos')
                ->has('rows', 2)
                ->where('rows.0.product', 'Vaso Espiral')
                ->where('rows.0.sku', 'VASO-P')
                ->where('rows.0.site.priceCents', 2490)
                ->where('rows.0.etsy.priceCents', 2490)
                ->where('rows.0.priceSource', 'site')
                ->has('settings.markupPercent')
                ->has('settings.feePercent'));

        // E tudo conta nossa: ver margens nao gasta um pedido a Etsy.
        Http::assertNothingSent();
    }

    public function test_the_pricing_settings_are_written_the_way_people_write_them(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.etsy.precos.index'))
            ->patch(route('admin.etsy.precos.definicoes'), [
                'markup_percent' => '12,5',
                'fee_percent' => '10,5',
                'fee_fixed' => '0,48',
                'mto_quantity' => 20,
                'stock_buffer' => 1,
                'default_taxonomy_id' => 1234,
                'who_made' => 'i_did',
                'when_made' => 'made_to_order',
            ])
            ->assertRedirect(route('admin.etsy.precos.index'))
            ->assertSessionHasNoErrors();

        $settings = app(EtsySettings::class);
        $this->assertSame(1250, $settings->markupBp());
        $this->assertSame(1050, $settings->feePercentageBp());
        $this->assertSame(48, $settings->feeFixedCents());
        $this->assertSame(20, $settings->mtoQuantity());
        $this->assertSame(1, $settings->stockBuffer());
        $this->assertSame(1234, $settings->defaultTaxonomyId());
    }

    public function test_only_admins_see_the_margins(): void
    {
        $this->actingAs(User::factory()->production()->create())->get(route('admin.etsy.precos.index'))->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->patch(route('admin.etsy.precos.definicoes'), ['markup_percent' => '50'])
            ->assertForbidden();
    }
}
