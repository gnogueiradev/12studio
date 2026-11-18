<?php

namespace Tests\Feature\Shop;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Variant;
use App\Shop\Buyable;
use App\Shop\CartQuoter;
use App\Shop\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A unica conta do carrinho: precos e totais lidos da base de dados de cada
 * vez, com o desconto e os portes das mesmas regras da encomenda.
 */
class CartQuoterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Um carrinho com uma linha de uma variante nova ao preco dado.
     *
     * @param  array<string, string>  $personalization
     */
    private function cartWith(int $priceCents, int $qty = 1, array $personalization = [], ?Product $product = null): Cart
    {
        $variant = Variant::factory()->for($product ?? Product::factory()->create())->create(['price_cents' => $priceCents]);
        $cart = Cart::factory()->create();

        $item = CartItem::factory()->for($cart)->for($variant)->state(['qty' => $qty]);
        $personalization === [] ? $item->create() : $item->personalised($personalization)->create();

        return $cart;
    }

    private function quote(Cart $cart, ?string $email = null): Quote
    {
        return app(CartQuoter::class)->quote($cart, $email);
    }

    public function test_prices_come_from_the_database_every_time(): void
    {
        $cart = $this->cartWith(1500, 2);

        $this->assertSame(3000, $this->quote($cart)->subtotalCents);

        Variant::query()->update(['price_cents' => 1800]);

        $this->assertSame(3600, $this->quote($cart)->subtotalCents);
    }

    public function test_the_surcharge_is_per_unit_and_only_when_personalised(): void
    {
        $product = Product::factory()->create([
            'personalization_fields' => [['key' => 'nome', 'label' => 'Nome', 'type' => 'text']],
            'personalization_surcharge_cents' => 300,
        ]);

        $line = $this->quote($this->cartWith(1500, 2, ['nome' => 'Ana'], $product))->lines[0];

        $this->assertSame(1500, $line->unitCents);
        $this->assertSame(300, $line->surchargeCents);
        $this->assertSame(3600, $line->lineCents);
        $this->assertSame(['nome' => 'Ana'], $line->personalization);

        $plain = $this->quote($this->cartWith(1500, 2, [], $product))->lines[0];

        $this->assertSame(0, $plain->surchargeCents);
        $this->assertSame(3000, $plain->lineCents);
    }

    public function test_a_line_carries_what_the_page_shows(): void
    {
        $product = Product::factory()->madeToOrder()->create(['name' => 'Vaso']);
        $variant = Variant::factory()->for($product)->create(['size_label' => 'Grande', 'sku' => 'VASO-G']);
        $cart = Cart::factory()->create();
        $item = CartItem::factory()->for($cart)->for($variant)->create(['qty' => 3]);

        $line = $this->quote($cart)->lines[0];

        $this->assertSame($item->id, $line->itemId);
        $this->assertSame('Vaso', $line->productName);
        $this->assertSame('Grande', $line->variantLabel);
        $this->assertSame('VASO-G', $line->sku);
        $this->assertSame(3, $line->qty);
        $this->assertSame(23, $line->vatRate);
        $this->assertSame('made_to_order', $line->mode);
        $this->assertSame([5, 7], $line->leadDays);
        $this->assertTrue($line->variant->relationLoaded('product'));
        $this->assertTrue($line->variant->relationLoaded('color'));
        $this->assertTrue($line->variant->relationLoaded('material'));
    }

    public function test_shipping_is_450_below_2500_and_free_from_there(): void
    {
        $below = $this->quote($this->cartWith(2400));

        $this->assertSame(450, $below->shippingCents);
        $this->assertSame(2850, $below->totalCents);
        $this->assertSame(100, $below->freeShippingLeftCents);

        $from = $this->quote($this->cartWith(2500));

        $this->assertSame(0, $from->shippingCents);
        $this->assertSame(0, $from->freeShippingLeftCents);
    }

    public function test_free_shipping_looks_at_the_subtotal_before_the_discount(): void
    {
        PromoCode::factory()->create(['code' => 'CINCO', 'value' => 500]);
        $cart = $this->cartWith(2600);
        $cart->update(['promo_code' => 'CINCO']);

        $quote = $this->quote($cart);

        $this->assertSame(500, $quote->discountCents);
        $this->assertSame(0, $quote->shippingCents);
        $this->assertSame(2100, $quote->totalCents);
        $this->assertSame('applied', $quote->promoStatus);
        $this->assertSame('CINCO', $quote->promoCode);
        $this->assertNotNull($quote->promoCodeId);
    }

    public function test_a_code_that_needs_an_email_waits_for_it(): void
    {
        PromoCode::factory()->create(['code' => 'PESSOAL', 'email' => 'ana@example.com']);
        $cart = $this->cartWith(2600);
        $cart->update(['promo_code' => 'PESSOAL']);

        $waiting = $this->quote($cart);

        $this->assertSame('pending_email', $waiting->promoStatus);
        $this->assertSame(0, $waiting->discountCents);
        $this->assertNotNull($waiting->promoMessage);

        $right = $this->quote($cart, 'Ana@Example.com');

        $this->assertSame('applied', $right->promoStatus);
        $this->assertSame(500, $right->discountCents);

        $wrong = $this->quote($cart, 'outra@example.com');

        $this->assertSame('refused', $wrong->promoStatus);
        $this->assertSame(0, $wrong->discountCents);
        $this->assertSame(trans('store.cart.promo.wrong_email'), $wrong->promoMessage);

        app()->setLocale('en');
        $this->assertSame('This code is personal and belongs to another email.', $this->quote($cart, 'outra@example.com')->promoMessage);
    }

    public function test_the_minimum_message_carries_the_amount(): void
    {
        PromoCode::factory()->create(['code' => 'MINIMO', 'min_subtotal_cents' => 5000]);
        $cart = $this->cartWith(2600);
        $cart->update(['promo_code' => 'MINIMO']);

        $this->assertSame(
            trans('store.cart.promo.below_minimum', ['amount' => '50,00']),
            $this->quote($cart)->promoMessage,
        );

        // Com os separadores da lingua de quem le.
        app()->setLocale('en');
        $this->assertSame('This code only applies from 50.00 €.', $this->quote($cart)->promoMessage);

        app()->setLocale('fr');
        $this->assertStringContainsString('50,00 €', (string) $this->quote($cart)->promoMessage);
    }

    public function test_a_refused_code_does_not_block_the_checkout(): void
    {
        PromoCode::factory()->create(['code' => 'VELHO', 'expires_at' => now()->subDay()]);
        $cart = $this->cartWith(2600);
        $cart->update(['promo_code' => 'VELHO']);

        $quote = $this->quote($cart);

        $this->assertSame('refused', $quote->promoStatus);
        $this->assertSame(0, $quote->discountCents);
        $this->assertNull($quote->promoCodeId);
        $this->assertTrue($quote->canCheckout());
    }

    public function test_a_cart_without_a_code_leaves_the_promo_fields_empty(): void
    {
        $quote = $this->quote($this->cartWith(2600));

        $this->assertNull($quote->promoCode);
        $this->assertNull($quote->promoStatus);
        $this->assertNull($quote->promoMessage);
        $this->assertNull($quote->promoCodeId);
    }

    public function test_the_code_is_checked_against_the_lines_that_can_be_bought(): void
    {
        PromoCode::factory()->create(['code' => 'MINIMO', 'min_subtotal_cents' => 2000]);
        $cart = $this->cartWith(1500);
        $gone = Variant::factory()->stock(0)->create(['price_cents' => 3000]);
        CartItem::factory()->for($cart)->for($gone)->create();
        $cart->update(['promo_code' => 'MINIMO']);

        $quote = $this->quote($cart);

        $this->assertSame(1500, $quote->subtotalCents);
        $this->assertSame('refused', $quote->promoStatus);
    }

    public function test_a_line_that_can_no_longer_be_bought_blocks_the_checkout(): void
    {
        $cart = $this->cartWith(1500);
        $other = Variant::factory()->create(['price_cents' => 1000, 'stock' => 5]);
        CartItem::factory()->for($cart)->for($other)->create();

        Variant::query()->where('id', '!=', $other->id)->update(['stock' => 0]);

        $quote = $this->quote($cart);

        $this->assertSame(Buyable::SOLD_OUT, $quote->lines[0]->problem);
        $this->assertNull($quote->lines[1]->problem);
        $this->assertTrue($quote->problems);
        $this->assertFalse($quote->canCheckout());
        $this->assertSame(1000, $quote->subtotalCents);
        $this->assertCount(2, $quote->lines);
    }

    public function test_an_empty_cart_cannot_check_out(): void
    {
        $quote = $this->quote(Cart::factory()->create());

        $this->assertSame([], $quote->lines);
        $this->assertSame(0, $quote->totalCents);
        $this->assertFalse($quote->belowMinimum);
        $this->assertFalse($quote->canCheckout());
    }

    public function test_a_total_below_50_cents_cannot_check_out(): void
    {
        PromoCode::factory()->create(['code' => 'QUASE', 'value' => 2580]);
        $cart = $this->cartWith(2600);
        $cart->update(['promo_code' => 'QUASE']);

        $quote = $this->quote($cart);

        $this->assertSame(20, $quote->totalCents);
        $this->assertTrue($quote->belowMinimum);
        $this->assertFalse($quote->canCheckout());
    }

    public function test_the_number_of_queries_does_not_grow_with_the_lines(): void
    {
        $small = Cart::factory()->create();
        CartItem::factory()->count(2)->for($small)->create();
        $big = Cart::factory()->create();
        CartItem::factory()->count(8)->for($big)->create();

        $count = function (Cart $cart): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->quote($cart);

            return count(DB::getQueryLog());
        };

        $this->assertSame($count($small), $count($big));
    }
}
