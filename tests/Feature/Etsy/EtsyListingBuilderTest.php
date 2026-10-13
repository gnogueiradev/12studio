<?php

namespace Tests\Feature\Etsy;

use App\Etsy\EtsyListingBuilder;
use App\Etsy\EtsyListingPayload;
use App\Etsy\EtsySettings;
use App\Models\Category;
use App\Models\Color;
use App\Models\EtsyListing;
use App\Models\EtsyVariant;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Variant;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Um produto do 12studio, visto como a Etsy o quer receber. O construtor nao
 * fala com ninguem: so decide o que seria enviado, e o que impede o envio.
 */
class EtsyListingBuilderTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    private function build(Product $product): EtsyListingPayload
    {
        return app(EtsyListingBuilder::class)->build($product->fresh());
    }

    private function listingFor(Product $product, array $attributes): EtsyListing
    {
        return EtsyListing::query()->create(['product_id' => $product->id, ...$attributes]);
    }

    // ── O anuncio ──────────────────────────────────────────────────────────

    public function test_without_etsy_content_the_listing_is_the_product(): void
    {
        $payload = $this->build($this->etsyReadyProduct());

        $this->assertSame([], $payload->blockers);
        $this->assertSame('Vaso Espiral', $payload->listing['title']);
        // O HTML do editor vira texto: a Etsy nao aceita marcacao.
        $this->assertSame("Um vaso impresso em PLA.\n\n• Feito no Porto\n• Leve", $payload->listing['description']);
        $this->assertSame(1234, $payload->listing['taxonomy_id']);
        $this->assertSame(555, $payload->listing['shipping_profile_id']);
        $this->assertSame('i_did', $payload->listing['who_made']);
        $this->assertSame('made_to_order', $payload->listing['when_made']);
        $this->assertSame('physical', $payload->listing['type']);
        $this->assertFalse($payload->listing['is_supply']);
    }

    public function test_the_etsy_content_of_the_product_wins(): void
    {
        $product = $this->etsyReadyProduct();
        $this->listingFor($product, [
            'title' => 'Spiral Vase — 3D printed',
            'description' => 'A vase.',
            'tags' => ['vase', 'decor'],
            'materials' => ['PLA plastic'],
            'taxonomy_id' => 999,
            'shipping_profile_id' => 556,
            'who_made' => 'collective',
            'shop_section_id' => 31,
        ]);

        $payload = $this->build($product);

        $this->assertSame('Spiral Vase — 3D printed', $payload->listing['title']);
        $this->assertSame('A vase.', $payload->listing['description']);
        $this->assertSame(['vase', 'decor'], $payload->listing['tags']);
        $this->assertSame(['PLA plastic'], $payload->listing['materials']);
        $this->assertSame(999, $payload->listing['taxonomy_id']);
        $this->assertSame(556, $payload->listing['shipping_profile_id']);
        $this->assertSame('collective', $payload->listing['who_made']);
        $this->assertSame(31, $payload->listing['shop_section_id']);
    }

    public function test_tags_come_from_the_product_and_obey_the_limits_of_etsy(): void
    {
        $product = $this->etsyReadyProduct();
        app(ProductService::class)->syncTags($product, [
            'natal', 'uma etiqueta comprida demais para a etsy',
            ...array_map(fn (int $i): string => "tag {$i}", range(1, 14)),
        ]);

        $payload = $this->build($product);

        // 13 no maximo, 20 caracteres cada: as outras ficam de fora, e diz-se.
        $this->assertCount(13, $payload->listing['tags']);
        $this->assertNotContains('uma etiqueta comprida demais para a etsy', $payload->listing['tags']);
        $this->assertNotEmpty(array_filter($payload->warnings, fn (string $w): bool => str_contains($w, 'etiqueta')));
    }

    public function test_the_section_comes_from_the_category_when_the_listing_has_none(): void
    {
        $category = Category::factory()->create();
        $product = $this->etsyReadyProduct(['category_id' => $category->id]);
        app(EtsySettings::class)->save(['section_map' => [$category->id => 32]]);

        $this->assertSame(32, $this->build($product)->listing['shop_section_id']);
    }

    // ── Variacoes ──────────────────────────────────────────────────────────

    public function test_only_what_actually_varies_becomes_a_variation(): void
    {
        $payload = $this->build($this->etsyReadyProduct());

        // Duas cores, um so material: a cor e a variacao; o material nao e
        // escolha nenhuma e vai para os materiais do anuncio.
        $products = $payload->inventory['products'];
        $this->assertCount(2, $products);
        $this->assertSame('VASO-P', $products[0]['sku']);
        $this->assertSame([[
            'property_id' => 513, 'property_name' => 'Cor', 'value_ids' => [], 'values' => ['Preto'],
        ]], $products[0]['property_values']);
        $this->assertSame(['Branco'], $products[1]['property_values'][0]['values']);

        $this->assertSame([513], $payload->inventory['price_on_property']);
        $this->assertSame([513], $payload->inventory['quantity_on_property']);
        $this->assertSame([513], $payload->inventory['sku_on_property']);
        $this->assertSame(['PLA'], $payload->listing['materials']);
        $this->assertSame([], $payload->inventoryQuery);
    }

    public function test_three_dimensions_become_three_variations(): void
    {
        $product = Product::factory()->create(['name' => 'Suporte']);
        $this->etsyReadyProduct();
        $pla = Material::factory()->create(['name' => 'PLA Mate']);
        $petg = Material::factory()->create(['name' => 'PETG']);
        $black = Color::factory()->create(['name' => 'Preto (mate)']);
        $white = Color::factory()->create(['name' => 'Branco Neve']);

        foreach ([[$black, $pla, 'S'], [$black, $petg, 'M'], [$white, $pla, 'M']] as $i => [$color, $material, $size]) {
            Variant::factory()->for($product)->create([
                'sku' => "SUP-{$i}", 'color_id' => $color->id, 'material_id' => $material->id, 'size_label' => $size,
            ]);
        }

        $payload = $this->build($product);

        $first = $payload->inventory['products'][0]['property_values'];
        $this->assertSame([513, 514, 516], array_column($first, 'property_id'));
        $this->assertSame(['Cor', 'Material', 'Tamanho'], array_column($first, 'property_name'));
        // A Etsy nao aceita parenteses no nome de um valor.
        $this->assertSame(['Preto mate'], $first[0]['values']);
        $this->assertSame(['max_variations_supported' => 3], $payload->inventoryQuery);
        $this->assertSame([513, 514, 516], $payload->inventory['price_on_property']);
    }

    public function test_a_single_variant_has_no_variations(): void
    {
        $product = $this->etsyReadyProduct();
        Variant::query()->where('sku', 'VASO-B')->delete();

        $payload = $this->build($product);

        $this->assertCount(1, $payload->inventory['products']);
        $this->assertSame([], $payload->inventory['products'][0]['property_values']);
        $this->assertSame([], $payload->inventory['price_on_property']);
    }

    public function test_the_property_ids_chosen_once_do_not_move(): void
    {
        $product = $this->etsyReadyProduct();
        // Um envio anterior pos a cor na 514 (por exemplo, quando havia
        // tamanhos). Trocar-lhe o numero agora baralhava o anuncio la.
        $this->listingFor($product, ['property_map' => ['color' => 514]]);

        $this->assertSame(514, $this->build($product)->inventory['products'][0]['property_values'][0]['property_id']);
    }

    // ── Precos e quantidades ───────────────────────────────────────────────

    public function test_stock_on_the_shelf_is_what_can_be_sold(): void
    {
        $product = $this->etsyReadyProduct();
        Variant::query()->where('sku', 'VASO-P')->update(['stock' => 5, 'reserved_stock' => 2]);
        app(EtsySettings::class)->save(['stock_buffer' => 1]);

        $offerings = array_column($this->build($product)->inventory['products'], 'offerings');

        // 5 - 2 reservadas - 1 de folga; 3 - 1 de folga.
        $this->assertSame(2, $offerings[0][0]['quantity']);
        $this->assertSame(2, $offerings[1][0]['quantity']);
        $this->assertSame(801, $offerings[0][0]['readiness_state_id']);
        $this->assertTrue($offerings[0][0]['is_enabled']);
    }

    public function test_made_to_order_products_offer_a_fixed_quantity(): void
    {
        $product = $this->etsyReadyProduct(['fulfillment_mode' => 'made_to_order']);
        Variant::query()->where('product_id', $product->id)->update(['stock' => 0]);
        app(EtsySettings::class)->save(['mto_quantity' => 25]);

        $offerings = array_column($this->build($product)->inventory['products'], 'offerings');

        // Nao ha prateleira a esvaziar: faz-se quando se vende.
        $this->assertSame(25, $offerings[0][0]['quantity']);
        $this->assertSame(802, $offerings[0][0]['readiness_state_id']);
    }

    public function test_the_price_is_the_one_of_the_site_unless_etsy_has_its_own(): void
    {
        $product = $this->etsyReadyProduct();
        $listing = $this->listingFor($product, []);
        $white = Variant::query()->where('sku', 'VASO-B')->sole();
        EtsyVariant::query()->create(['variant_id' => $white->id, 'listing_id' => $listing->id, 'etsy_price_cents' => 3200]);

        $offerings = array_column($this->build($product)->inventory['products'], 'offerings');

        $this->assertSame(24.9, $offerings[0][0]['price']);
        $this->assertSame(32.0, $offerings[1][0]['price']);
    }

    public function test_the_global_markup_applies_where_there_is_no_own_price(): void
    {
        $product = $this->etsyReadyProduct();
        app(EtsySettings::class)->save(['markup_bp' => 1000]);

        // 24,90 + 10 % = 27,39.
        $this->assertSame(27.39, $this->build($product)->inventory['products'][0]['offerings'][0]['price']);
    }

    public function test_hidden_variants_stay_out_and_switched_off_ones_go_disabled(): void
    {
        $product = $this->etsyReadyProduct();
        $listing = $this->listingFor($product, []);
        $third = Variant::factory()->for($product)->create([
            'sku' => 'VASO-V', 'active' => false, 'color_id' => Color::factory()->create(['name' => 'Verde'])->id,
        ]);
        $white = Variant::query()->where('sku', 'VASO-B')->sole();
        EtsyVariant::query()->create(['variant_id' => $white->id, 'listing_id' => $listing->id, 'enabled' => false]);

        $products = $this->build($product)->inventory['products'];

        $this->assertNotContains($third->sku, array_column($products, 'sku'));
        $this->assertFalse($products[1]['offerings'][0]['is_enabled']);
    }

    // ── Fotografias ────────────────────────────────────────────────────────

    public function test_photos_go_in_gallery_order_without_the_excluded_ones(): void
    {
        $product = $this->etsyReadyProduct();
        $listing = $this->listingFor($product, []);
        $second = ProductImage::factory()->for($product)->create(['sort_order' => 2, 'alt' => 'De lado']);
        $first = ProductImage::factory()->for($product)->primary()->create(['sort_order' => 1]);
        $hidden = ProductImage::factory()->for($product)->create(['sort_order' => 3]);
        $listing->images()->create(['product_image_id' => $hidden->id, 'excluded' => true]);

        $images = $this->build($product)->images;

        $this->assertSame([$first->id, $second->id], array_column($images, 'product_image_id'));
        $this->assertSame([1, 2], array_column($images, 'rank'));
        $this->assertSame('De lado', $images[1]['alt']);
    }

    // ── O que impede o envio ───────────────────────────────────────────────

    public function test_it_says_what_is_missing_before_etsy_does(): void
    {
        $material = Material::factory()->create();
        $product = Product::factory()->create(['description' => null]);
        Variant::factory()->for($product)->stock(0)->create(['material_id' => $material->id]);

        $blockers = implode(' | ', $this->build($product)->blockers);

        $this->assertStringContainsString('categoria', $blockers);
        $this->assertStringContainsString('perfil de envio', $blockers);
        $this->assertStringContainsString('perfil de processamento', $blockers);
        $this->assertStringContainsString('descrição', $blockers);
        $this->assertStringContainsString('stock', $blockers);
    }

    public function test_two_variants_that_look_the_same_to_the_buyer_block_the_listing(): void
    {
        $product = $this->etsyReadyProduct();
        $black = Variant::query()->where('sku', 'VASO-P')->sole();
        Variant::factory()->for($product)->create(['sku' => 'VASO-P2', 'color_id' => $black->color_id, 'material_id' => $black->material_id]);

        $blockers = implode(' | ', $this->build($product)->blockers);

        // Duas variantes "Preto": o comprador nao tinha como escolher entre elas.
        $this->assertStringContainsString('VASO-P', $blockers);
        $this->assertStringContainsString('VASO-P2', $blockers);
    }

    public function test_a_product_without_variants_cannot_be_listed(): void
    {
        $this->etsyReadyProduct();
        $product = Product::factory()->create();

        $this->assertStringContainsString('variante', implode(' | ', $this->build($product)->blockers));
    }

    public function test_a_title_too_long_for_etsy_blocks_instead_of_being_cut(): void
    {
        $product = $this->etsyReadyProduct();
        $this->listingFor($product, ['title' => str_repeat('a', 141)]);

        $this->assertStringContainsString('140', implode(' | ', $this->build($product)->blockers));
    }

    // ── A impressao digital ────────────────────────────────────────────────

    public function test_the_hash_changes_only_when_what_would_be_sent_changes(): void
    {
        $product = $this->etsyReadyProduct();
        $before = $this->build($product)->hash();

        $this->assertSame($before, $this->build($product)->hash());

        Variant::query()->where('sku', 'VASO-P')->update(['price_cents' => 2590]);
        $this->assertNotSame($before, $this->build($product)->hash());

        Variant::query()->where('sku', 'VASO-P')->update(['price_cents' => 2490]);
        $product->update(['name' => 'Vaso Espiral XL']);
        $this->assertNotSame($before, $this->build($product)->hash());
    }
}
