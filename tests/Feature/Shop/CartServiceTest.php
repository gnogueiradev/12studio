<?php

namespace Tests\Feature\Shop;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Variant;
use App\Shop\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * As escritas do carrinho: criar, somar, limitar e limpar a personalizacao
 * contra os campos do produto.
 */
class CartServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CartService
    {
        return app(CartService::class);
    }

    private function personalisable(): Product
    {
        return Product::factory()->create([
            'personalization_fields' => [
                ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true, 'maxLength' => 10],
                ['key' => 'numero', 'label' => 'Número', 'type' => 'number', 'required' => false],
            ],
            'personalization_surcharge_cents' => 300,
        ]);
    }

    public function test_find_never_creates_a_cart(): void
    {
        $this->assertNull($this->service()->find(null));
        $this->assertNull($this->service()->find(''));
        $this->assertNull($this->service()->find(str_repeat('a', 64)));
        $this->assertSame(0, Cart::query()->count());

        $cart = Cart::factory()->create();

        $this->assertTrue($cart->is($this->service()->find($cart->token)));
    }

    public function test_add_creates_the_cart_with_a_64_character_token(): void
    {
        $variant = Variant::factory()->create();

        $cart = $this->service()->add(null, $variant, 2, []);

        $this->assertSame(64, strlen($cart->token));
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(2, $cart->items()->first()->qty);
        $this->assertNull($cart->items()->first()->personalization);
    }

    public function test_the_same_variant_and_personalisation_add_up_on_one_line(): void
    {
        $variant = Variant::factory()->create(['product_id' => $this->personalisable()->id]);

        $cart = $this->service()->add(null, $variant, 1, ['nome' => 'Ana']);
        $this->service()->add($cart, $variant, 2, ['nome' => ' Ana ']);

        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(3, $cart->items()->first()->qty);
    }

    public function test_a_different_personalisation_makes_another_line(): void
    {
        $variant = Variant::factory()->create(['product_id' => $this->personalisable()->id]);

        $cart = $this->service()->add(null, $variant, 1, ['nome' => 'Ana']);
        $this->service()->add($cart, $variant, 1, ['nome' => 'Rui']);

        $this->assertSame(2, $cart->items()->count());
    }

    public function test_the_31st_line_is_refused(): void
    {
        $cart = Cart::factory()->create();
        CartItem::factory()->count(30)->for($cart)->create();

        try {
            $this->service()->add($cart, Variant::factory()->create(), 1, []);
            $this->fail('A 31.ª linha devia ser recusada.');
        } catch (ValidationException) {
            $this->assertSame(30, $cart->items()->count());
        }
    }

    public function test_a_line_can_still_grow_when_the_cart_is_full(): void
    {
        $cart = Cart::factory()->create();
        CartItem::factory()->count(29)->for($cart)->create();
        $variant = Variant::factory()->create();
        $this->service()->add($cart, $variant, 1, []);

        $this->service()->add($cart, $variant, 1, []);

        $this->assertSame(30, $cart->items()->count());
        $this->assertSame(2, $cart->items()->where('variant_id', $variant->id)->value('qty'));
    }

    public function test_a_quantity_of_21_is_refused_and_nothing_is_created(): void
    {
        $variant = Variant::factory()->stock(50)->create();

        try {
            $this->service()->add(null, $variant, 21, []);
            $this->fail('A quantidade 21 devia ser recusada.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('qty', $e->errors());
            $this->assertSame(0, Cart::query()->count());
        }
    }

    public function test_adding_up_past_the_limit_is_refused(): void
    {
        $variant = Variant::factory()->stock(50)->create();
        $cart = $this->service()->add(null, $variant, 20, []);

        $this->expectException(ValidationException::class);

        $this->service()->add($cart, $variant, 1, []);
    }

    public function test_the_stock_ceiling_is_respected(): void
    {
        $variant = Variant::factory()->stock(2)->create();

        $this->expectException(ValidationException::class);

        $this->service()->add(null, $variant, 3, []);
    }

    public function test_set_qty_remove_and_promo_code_touch_the_cart(): void
    {
        $variant = Variant::factory()->stock(10)->create();
        $cart = $this->service()->add(null, $variant, 1, []);
        $item = $cart->items()->first();

        $this->travel(2)->days();
        $this->service()->setQty($item, 4);
        $this->assertSame(4, $item->fresh()->qty);
        $this->assertTrue($cart->fresh()->updated_at->isToday());

        $this->travel(2)->days();
        $this->service()->setPromoCode($cart, ' ola ');
        $this->assertSame('OLA', $cart->fresh()->promo_code);
        $this->assertTrue($cart->fresh()->updated_at->isToday());

        $this->service()->setPromoCode($cart, '  ');
        $this->assertNull($cart->fresh()->promo_code);

        $this->travel(2)->days();
        $this->service()->remove($item);
        $this->assertSame(0, $cart->items()->count());
        $this->assertTrue($cart->fresh()->updated_at->isToday());
    }

    public function test_set_qty_refuses_what_cannot_be_bought(): void
    {
        $variant = Variant::factory()->stock(2)->create();
        $cart = $this->service()->add(null, $variant, 1, []);

        $this->expectException(ValidationException::class);

        $this->service()->setQty($cart->items()->first(), 3);
    }

    public function test_clear_removes_the_lines_and_the_code(): void
    {
        $cart = $this->service()->add(null, Variant::factory()->create(), 1, []);
        $this->service()->setPromoCode($cart, 'ABC');

        $this->service()->clear($cart);

        $this->assertSame(0, $cart->items()->count());
        $this->assertNull($cart->fresh()->promo_code);
    }

    public function test_personalisation_keeps_only_the_products_fields_cleaned(): void
    {
        $cleaned = $this->service()->personalizationFor($this->personalisable(), [
            'nome' => '  <b>Ana</b> ',
            'numero' => '',
            'outro' => 'lixo',
        ]);

        $this->assertSame(['nome' => 'Ana'], $cleaned);
    }

    public function test_personalisation_enforces_required_max_length_and_numbers(): void
    {
        $product = $this->personalisable();

        foreach ([
            [[], 'personalization.nome'],
            [['nome' => '<i></i>'], 'personalization.nome'],
            [['nome' => 'Nome demasiado grande'], 'personalization.nome'],
            [['nome' => 'Ana', 'numero' => 'sete'], 'personalization.numero'],
        ] as [$input, $key]) {
            try {
                $this->service()->personalizationFor($product, $input);
                $this->fail("Devia recusar {$key}.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        }

        $this->assertSame(['nome' => 'Ana', 'numero' => '7'], $this->service()->personalizationFor($product, ['nome' => 'Ana', 'numero' => '7']));
    }

    public function test_a_product_without_fields_has_no_personalisation(): void
    {
        $this->assertSame([], $this->service()->personalizationFor(Product::factory()->create(), ['nome' => 'Ana']));
    }

    public function test_the_hash_ignores_key_order_and_an_empty_choice_has_a_hash_too(): void
    {
        $this->assertSame(CartItem::hashFor(['a' => '1', 'b' => '2']), CartItem::hashFor(['b' => '2', 'a' => '1']));
        $this->assertSame(32, strlen(CartItem::hashFor([])));
    }
}
