<?php

namespace Tests\Feature\Etsy;

use App\Etsy\EtsyListingMatcher;
use App\Models\EtsyListing;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A que produto pertence este anuncio? Uma sugestao, nunca uma decisao: quem
 * liga e o dono.
 */
class EtsyListingMatcherTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    private function suggest(array $listing): ?Product
    {
        return app(EtsyListingMatcher::class)->suggest([$this->etsyListing($listing)])[7001] ?? null;
    }

    public function test_the_reference_decides_first(): void
    {
        $vase = Product::factory()->create(['name' => 'Peça A']);
        Variant::factory()->for($vase)->create(['sku' => 'VASO-1']);
        Variant::factory()->for($vase)->create(['sku' => 'VASO-2']);

        // O nome engana de proposito: a referencia e que e a identidade.
        $decoy = Product::factory()->create(['name' => 'Spiral Vase']);
        Variant::factory()->for($decoy)->create(['sku' => 'OUTRO-1']);

        $this->assertSame($vase->id, $this->suggest([])?->id);
    }

    public function test_more_references_in_common_wins(): void
    {
        $one = Product::factory()->create();
        Variant::factory()->for($one)->create(['sku' => 'VASO-1']);

        $both = Product::factory()->create();
        Variant::factory()->for($both)->create(['sku' => 'vaso-2']);
        Variant::factory()->for($both)->create(['sku' => 'VASO-3']);

        $this->assertSame($both->id, $this->suggest(['skus' => ['VASO-2', 'VASO-3', 'VASO-1']])?->id);
    }

    public function test_without_references_the_name_is_the_next_best_thing(): void
    {
        Product::factory()->create(['name' => 'Suporte de telemóvel']);
        $vase = Product::factory()->create(['name' => 'Vaso Espiral']);

        $this->assertSame($vase->id, $this->suggest(['skus' => [], 'title' => 'Vaso espiral impresso em 3D — preto'])?->id);
    }

    public function test_nothing_close_means_no_suggestion(): void
    {
        Product::factory()->create(['name' => 'Suporte de telemóvel']);

        // Uma sugestao errada e pior do que nenhuma: liga-se com um clique.
        $this->assertNull($this->suggest(['skus' => [], 'title' => 'Candeeiro de mesa']));
    }

    public function test_a_product_already_linked_is_not_offered_again(): void
    {
        $vase = Product::factory()->create(['name' => 'Vaso Espiral']);
        Variant::factory()->for($vase)->create(['sku' => 'VASO-1']);
        EtsyListing::query()->create(['product_id' => $vase->id, 'etsy_listing_id' => 6000]);

        $this->assertNull($this->suggest([]));
    }

    public function test_a_deleted_product_is_not_offered(): void
    {
        $vase = Product::factory()->create(['name' => 'Vaso Espiral']);
        Variant::factory()->for($vase)->create(['sku' => 'VASO-1']);
        $vase->delete();

        $this->assertNull($this->suggest([]));
    }
}
