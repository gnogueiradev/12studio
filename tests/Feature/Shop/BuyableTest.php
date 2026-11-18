<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Variant;
use App\Shop\Buyable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A regra unica do que se pode comprar: a pagina do produto, o carrinho e a
 * compra perguntam todos a Buyable, por isso cada linha da tabela do desenho
 * tem aqui o seu teste.
 */
class BuyableTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $variant */
    private function variant(array $variant = [], ?Product $product = null): Variant
    {
        $product ??= Product::factory()->create();

        return Variant::factory()->for($product)->create($variant);
    }

    public function test_custom_products_are_never_bought(): void
    {
        $variant = $this->variant(product: Product::factory()->custom()->create());

        $result = Buyable::check($variant, 1);

        $this->assertFalse($result->ok);
        $this->assertSame(Buyable::CUSTOM, $result->reason);
        $this->assertNull($result->mode);
    }

    public function test_made_to_order_is_bought_with_production(): void
    {
        $variant = $this->variant(['stock' => 0], Product::factory()->madeToOrder()->create());

        $result = Buyable::check($variant, 4);

        $this->assertTrue($result->ok);
        $this->assertSame('made_to_order', $result->mode);
        $this->assertNull($result->maxQty);
        $this->assertNull($result->reason);
    }

    public function test_in_stock_with_enough_stock_is_bought_from_the_shelf(): void
    {
        $result = Buyable::check($this->variant(['stock' => 5]), 3);

        $this->assertTrue($result->ok);
        $this->assertSame('in_stock', $result->mode);
        $this->assertSame(5, $result->maxQty);
    }

    public function test_with_backorder_the_stock_is_not_the_ceiling(): void
    {
        $variant = $this->variant(['stock' => 5], Product::factory()->create(['allow_backorder' => true]));

        $result = Buyable::check($variant, 3);

        $this->assertTrue($result->ok);
        $this->assertSame('in_stock', $result->mode);
        $this->assertNull($result->maxQty);
    }

    public function test_short_stock_with_backorder_makes_the_whole_line_to_order(): void
    {
        $variant = $this->variant(['stock' => 2], Product::factory()->create(['allow_backorder' => true]));

        $result = Buyable::check($variant, 3);

        $this->assertTrue($result->ok);
        $this->assertSame('made_to_order', $result->mode);
        $this->assertNull($result->maxQty);
    }

    public function test_short_stock_without_backorder_stops_at_what_there_is(): void
    {
        $result = Buyable::check($this->variant(['stock' => 2]), 3);

        $this->assertFalse($result->ok);
        $this->assertSame(Buyable::OVER_STOCK, $result->reason);
        $this->assertSame(2, $result->maxQty);
    }

    public function test_no_stock_without_backorder_is_sold_out(): void
    {
        $result = Buyable::check($this->variant(['stock' => 0]), 1);

        $this->assertFalse($result->ok);
        $this->assertSame(Buyable::SOLD_OUT, $result->reason);
    }

    public function test_reserved_stock_does_not_count(): void
    {
        $result = Buyable::check($this->variant(['stock' => 5, 'reserved_stock' => 4]), 2);

        $this->assertFalse($result->ok);
        $this->assertSame(Buyable::OVER_STOCK, $result->reason);
        $this->assertSame(1, $result->maxQty);
    }

    public function test_draft_archived_deleted_and_hidden_variants_are_not_for_sale(): void
    {
        $draft = $this->variant(product: Product::factory()->create(['status' => 'draft']));
        $archived = $this->variant(product: Product::factory()->archived()->create());
        $deleted = $this->variant(product: Product::factory()->create());
        $deleted->product->delete();
        $inactive = $this->variant(['active' => false]);
        $offColor = $this->variant(['color_id' => Color::factory()->create(['is_active' => false])->id]);
        $archivedCategory = $this->variant(product: Product::factory()->create([
            'category_id' => Category::factory()->create(['status' => 'archived'])->id,
        ]));

        foreach ([$draft, $archived, $deleted, $inactive, $offColor, $archivedCategory] as $variant) {
            $result = Buyable::check($variant->fresh(), 1);

            $this->assertFalse($result->ok);
            $this->assertSame(Buyable::NOT_FOR_SALE, $result->reason);
        }
    }

    public function test_quantity_is_between_one_and_the_limit(): void
    {
        $variant = $this->variant(['stock' => 50]);

        foreach ([0, config('shop.cart.max_qty') + 1] as $qty) {
            $result = Buyable::check($variant, $qty);

            $this->assertFalse($result->ok);
            $this->assertSame(Buyable::BAD_QTY, $result->reason);
        }

        $this->assertTrue(Buyable::check($variant, config('shop.cart.max_qty'))->ok);
        $this->assertTrue(Buyable::check($variant, 1)->ok);
    }

    public function test_max_qty_is_the_smaller_of_the_stock_and_the_general_limit(): void
    {
        $this->assertSame(20, Buyable::check($this->variant(['stock' => 50]), 1)->maxQty);
        $this->assertSame(5, Buyable::check($this->variant(['stock' => 5]), 1)->maxQty);
    }

    public function test_a_hidden_variant_of_a_visible_product_is_not_for_sale(): void
    {
        $product = Product::factory()->create();
        $visible = $this->variant([], $product);
        $inactive = $this->variant(['active' => false], $product);
        $offColor = $this->variant(['color_id' => Color::factory()->create(['is_active' => false])->id], $product);

        $this->assertTrue(Buyable::check($visible, 1)->ok);
        $this->assertSame(Buyable::NOT_FOR_SALE, Buyable::check($inactive, 1)->reason);
        $this->assertSame(Buyable::NOT_FOR_SALE, Buyable::check($offColor, 1)->reason);
    }

    public function test_for_sale_answers_a_whole_list_with_a_constant_number_of_queries(): void
    {
        $first = Product::factory()->create();
        $second = Product::factory()->archived()->create();
        $variants = collect([
            $this->variant([], $first),
            $this->variant([], $first),
            $this->variant(['active' => false], $first),
            $this->variant([], $second),
            $this->variant([], $second),
        ]);

        DB::enableQueryLog();
        Buyable::forSale([$variants[0]]);
        $one = count(DB::getQueryLog());

        DB::flushQueryLog();
        $answers = Buyable::forSale($variants);
        $many = count(DB::getQueryLog());

        $this->assertSame($one, $many);
        $this->assertSame([
            $variants[0]->id => true,
            $variants[1]->id => true,
            $variants[2]->id => false,
            $variants[3]->id => false,
            $variants[4]->id => false,
        ], $answers);
    }

    public function test_check_with_a_known_answer_runs_no_query(): void
    {
        $variant = $this->variant(['stock' => 5]);
        $variant->load('product');

        DB::enableQueryLog();
        $result = Buyable::check($variant, 1, true);

        $this->assertCount(0, DB::getQueryLog());
        $this->assertTrue($result->ok);
        $this->assertSame(Buyable::NOT_FOR_SALE, Buyable::check($variant, 1, false)->reason);
    }
}
