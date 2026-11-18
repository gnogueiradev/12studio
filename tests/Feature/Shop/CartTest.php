<?php

namespace Tests\Feature\Shop;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\User;
use App\Models\Variant;
use App\Services\SettingService;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Stripe\FakesStripe;
use Tests\TestCase;

/**
 * O carrinho por HTTP: as rotas, o cookie que leva o token, o que cada escrita
 * devolve e o que o cabecalho da montra mostra. As contas em si estao nos
 * testes do CartService e do CartQuoter.
 */
class CartTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
        $this->fakeStripe();
        $this->mode('on');
        RateLimiter::clear('cart');
    }

    private function mode(string $mode): void
    {
        app(SettingService::class)->set(StoreSettings::KEY_SHOP, $mode);
    }

    private function cookie(): string
    {
        return (string) config('shop.cart.cookie');
    }

    /** Um carrinho que o visitante "tem": o cookie com o token vai em cada pedido. */
    private function holding(Cart $cart): static
    {
        return $this->withCookie($this->cookie(), $cart->token);
    }

    /** @return array<string, mixed> */
    private function flashed(): array
    {
        return (array) session('inertia.flash_data.cart');
    }

    public function test_with_the_shop_off_the_cart_does_not_exist(): void
    {
        $this->mode('off');
        $variant = Variant::factory()->create();

        $this->get('/carrinho')->assertNotFound();
        $this->post('/carrinho/itens', ['variant_id' => $variant->id, 'qty' => 1])->assertNotFound();
        $this->assertSame(0, Cart::query()->count());
    }

    public function test_opening_pages_never_creates_a_cart(): void
    {
        $product = Product::factory()->create(['slug' => 'vaso']);
        Variant::factory()->for($product)->create();

        $cart = $this->get('/carrinho');
        $cart->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('store/cart')
            ->where('cart.count', 0)
            ->where('cart.canCheckout', false));
        $cart->assertCookieMissing($this->cookie());
        $cart->assertSee('<meta name="robots" content="noindex">', false);

        $this->get('/produtos/vaso')->assertOk()->assertCookieMissing($this->cookie());

        $this->assertSame(0, Cart::query()->count());
    }

    public function test_adding_creates_the_cart_and_sets_the_cookie(): void
    {
        $variant = Variant::factory()->create(['price_cents' => 1500]);

        $response = $this->post('/carrinho/itens', ['variant_id' => $variant->id, 'qty' => 2]);

        $response->assertRedirect();
        $this->assertSame(1, Cart::query()->count());
        $cart = Cart::query()->firstOrFail();
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(2, $cart->items()->firstOrFail()->qty);

        $response->assertCookie($this->cookie());
        $cookie = $response->getCookie($this->cookie());
        $this->assertNotNull($cookie);
        $this->assertSame($cart->token, $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertEqualsWithDelta(
            now()->addDays((int) config('shop.cart.days'))->timestamp,
            $cookie->getExpiresTime(),
            120,
        );

        $this->assertSame(3000, $this->flashed()['subtotalCents']);
        $this->assertSame(2, $this->flashed()['count']);
    }

    public function test_adding_again_keeps_the_cart_of_the_cookie(): void
    {
        $variant = Variant::factory()->create();
        $cart = Cart::factory()->create();

        $this->holding($cart)->post('/carrinho/itens', ['variant_id' => $variant->id, 'qty' => 1])
            ->assertRedirect();

        $this->assertSame(1, Cart::query()->count());
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_the_price_in_the_request_is_ignored(): void
    {
        $variant = Variant::factory()->create(['price_cents' => 2200]);

        $this->post('/carrinho/itens', [
            'variant_id' => $variant->id,
            'qty' => 1,
            'price_cents' => 1,
            'unit_cents' => 1,
            'total_cents' => 1,
        ])->assertRedirect();

        $this->assertSame(2200, $this->flashed()['subtotalCents']);
        $this->assertSame(2200, $this->flashed()['lines'][0]['unitCents']);
    }

    public function test_what_cannot_be_bought_is_refused(): void
    {
        $custom = Variant::factory()->for(Product::factory()->custom()->create())->create();
        $soldOut = Variant::factory()->create(['stock' => 0]);
        $draft = Variant::factory()->for(Product::factory()->create(['status' => 'draft']))->create();

        foreach ([$custom, $soldOut, $draft] as $variant) {
            $this->post('/carrinho/itens', ['variant_id' => $variant->id, 'qty' => 1])
                ->assertSessionHasErrors('qty');
        }

        $this->post('/carrinho/itens', ['variant_id' => 999999, 'qty' => 1])
            ->assertSessionHasErrors('variant_id');
        $this->post('/carrinho/itens', ['variant_id' => $soldOut->id, 'qty' => 0])
            ->assertSessionHasErrors('qty');
        $this->post('/carrinho/itens', ['variant_id' => $soldOut->id, 'qty' => 21])
            ->assertSessionHasErrors('qty');

        $this->assertSame(0, Cart::query()->count());
    }

    public function test_personalisation_is_validated_against_the_product(): void
    {
        $product = Product::factory()->create([
            'personalization_fields' => [
                ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true, 'maxLength' => 10],
            ],
        ]);
        $variant = Variant::factory()->for($product)->create();
        $post = fn (array $personalization) => $this->post('/carrinho/itens', [
            'variant_id' => $variant->id,
            'qty' => 1,
            'personalization' => $personalization,
        ]);

        $post(['nome' => ''])->assertSessionHasErrors('personalization.nome');
        $post(['nome' => 'Nome muito comprido'])->assertSessionHasErrors('personalization.nome');
        $this->assertSame(0, Cart::query()->count());

        $post(['nome' => '<b>Ana</b>', 'intruso' => 'x'])->assertSessionDoesntHaveErrors();

        $this->assertSame(['nome' => 'Ana'], CartItem::query()->firstOrFail()->personalization);
        $this->assertSame(
            [['label' => 'Nome', 'value' => 'Ana']],
            $this->flashed()['lines'][0]['personalization'],
        );
    }

    public function test_quantity_changes_and_removal_only_touch_my_cart(): void
    {
        $mine = Cart::factory()->create();
        $theirs = Cart::factory()->create();
        $theirItem = CartItem::factory()->for($theirs)->create(['qty' => 1]);

        $this->holding($mine)->patch("/carrinho/itens/{$theirItem->id}/quantidade", ['qty' => 5])->assertNotFound();
        $this->holding($mine)->delete("/carrinho/itens/{$theirItem->id}/remover")->assertNotFound();

        $this->assertSame(1, $theirItem->fresh()->qty);

        $item = CartItem::factory()->for($mine)->create(['qty' => 1]);

        $this->holding($mine)->patch("/carrinho/itens/{$item->id}/quantidade", ['qty' => 4])->assertRedirect();
        $this->assertSame(4, $item->fresh()->qty);
        $this->assertSame(4, $this->flashed()['count']);

        $this->holding($mine)->delete("/carrinho/itens/{$item->id}/remover")->assertRedirect();
        $this->assertNull($item->fresh());
        $this->assertSame(0, $this->flashed()['count']);
    }

    public function test_quantity_cannot_pass_the_stock(): void
    {
        $cart = Cart::factory()->create();
        $item = CartItem::factory()->for($cart)->for(Variant::factory()->create(['stock' => 2]))->create(['qty' => 2]);

        $this->holding($cart)->patch("/carrinho/itens/{$item->id}/quantidade", ['qty' => 3])
            ->assertSessionHasErrors('qty');

        $this->assertSame(2, $item->fresh()->qty);
    }

    public function test_a_code_is_kept_and_quoted(): void
    {
        PromoCode::factory()->create(['code' => 'VERAO', 'value' => 500]);
        $cart = Cart::factory()->create();
        CartItem::factory()->for($cart)->for(Variant::factory()->create(['price_cents' => 3000]))->create(['qty' => 1]);

        $this->holding($cart)->post('/carrinho/codigo', ['promo_code' => ' verao '])->assertRedirect();

        $this->assertSame('VERAO', $cart->fresh()->promo_code);
        $this->assertSame(500, $this->flashed()['discountCents']);
        $this->assertSame('applied', $this->flashed()['promo']['status']);

        $this->holding($cart)->delete('/carrinho/codigo/remover')->assertRedirect();

        $this->assertNull($cart->fresh()->promo_code);
        $this->assertSame(0, $this->flashed()['discountCents']);
        $this->assertNull($this->flashed()['promo']);
    }

    public function test_writes_without_a_cart_are_a_404(): void
    {
        $item = CartItem::factory()->for(Cart::factory()->create())->create();

        $this->post('/carrinho/codigo', ['promo_code' => 'VERAO'])->assertNotFound();
        $this->delete('/carrinho/codigo/remover')->assertNotFound();
        $this->patch("/carrinho/itens/{$item->id}/quantidade", ['qty' => 2])->assertNotFound();
        $this->delete("/carrinho/itens/{$item->id}/remover")->assertNotFound();
        $this->assertSame(1, Cart::query()->count());
    }

    public function test_the_header_count_follows_the_cart(): void
    {
        $cart = Cart::factory()->create();
        CartItem::factory()->for($cart)->for(Variant::factory()->create())->create(['qty' => 2]);
        CartItem::factory()->for($cart)->for(Variant::factory()->create())->create(['qty' => 1]);

        $this->holding($cart)->get('/produtos')->assertInertia(fn (Assert $page) => $page
            ->where('storefront.cartCount', 3)
            ->where('storefront.shop', true)
            ->where('storefront.maxQty', 20));
    }

    public function test_the_header_count_is_zero_without_a_cookie(): void
    {
        CartItem::factory()->for(Cart::factory()->create())->create(['qty' => 2]);

        $this->get('/produtos')->assertInertia(fn (Assert $page) => $page
            ->where('storefront.cartCount', 0)
            ->where('storefront.shop', true));
    }

    public function test_the_header_does_not_offer_the_cart_when_the_shop_is_off(): void
    {
        $this->mode('off');

        $this->get('/produtos')->assertInertia(fn (Assert $page) => $page
            ->where('storefront.shop', false)
            ->where('storefront.cartCount', 0));
    }

    public function test_the_cart_page_shapes_the_lines_for_the_page(): void
    {
        $cart = Cart::factory()->create();
        $product = Product::factory()->create(['slug' => 'vaso', 'name' => 'Vaso']);
        $variant = Variant::factory()->for($product)->create(['price_cents' => 1000, 'size_label' => 'M']);
        $item = CartItem::factory()->for($cart)->for($variant)->create(['qty' => 3]);

        $this->holding($cart)->get('/carrinho')->assertInertia(fn (Assert $page) => $page
            ->where('cart.count', 3)
            ->where('cart.subtotalCents', 3000)
            ->where('cart.lines.0.id', $item->id)
            ->where('cart.lines.0.variantId', $variant->id)
            ->where('cart.lines.0.name', 'Vaso')
            ->where('cart.lines.0.label', 'M')
            ->where('cart.lines.0.href', "/produtos/vaso?variante={$variant->id}")
            ->where('cart.lines.0.lineCents', 3000)
            ->where('cart.lines.0.problem', null)
            ->where('cart.canCheckout', true));
    }

    public function test_the_cart_works_under_a_language_prefix(): void
    {
        $variant = Variant::factory()->create();

        $this->post('/en/carrinho/itens', ['variant_id' => $variant->id, 'qty' => 1])->assertRedirect();
        $cart = Cart::query()->firstOrFail();

        $this->holding($cart)->get('/en/carrinho')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('store/cart')
            ->where('cart.count', 1));
    }

    public function test_staff_mode_shows_the_cart_only_to_admins(): void
    {
        $this->mode('staff');

        $this->get('/carrinho')->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())->get('/carrinho')->assertOk();
    }

    public function test_the_cart_is_throttled_per_ip(): void
    {
        $variant = Variant::factory()->create(['stock' => 0]);

        for ($i = 0; $i < 60; $i++) {
            $this->post('/carrinho/itens', ['variant_id' => $variant->id, 'qty' => 1])->assertSessionHasErrors('qty');
        }

        $this->post('/carrinho/itens', ['variant_id' => $variant->id, 'qty' => 1])->assertStatus(429);
    }

    public function test_the_cart_is_capped_per_day_per_ip(): void
    {
        $variant = Variant::factory()->create(['stock' => 0]);

        // Duas mil escritas a serio demoravam demais: o dia do limite enche-se
        // pela mesma chave que o throttle usa, e o limite por minuto fica
        // intacto.
        $day = collect(RateLimiter::limiter('cart')(Request::create('/carrinho/itens', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1'])))
            ->firstWhere('decaySeconds', 86400);
        $this->assertNotNull($day);
        $this->assertSame(2000, $day->maxAttempts);

        $this->post('/carrinho/itens', ['variant_id' => $variant->id, 'qty' => 1])->assertSessionHasErrors('qty');

        RateLimiter::increment(md5('cart'.$day->key), 86400, 1999);

        $this->post('/carrinho/itens', ['variant_id' => $variant->id, 'qty' => 1])->assertStatus(429);
    }

    public function test_the_error_pages_still_render_with_the_header_props(): void
    {
        $this->get('/carrinho/nao-existe')->assertNotFound();
    }
}
