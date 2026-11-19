<?php

namespace Tests\Feature\Customers;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\SettingService;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Stripe\FakesStripe;
use Tests\TestCase;

/**
 * "Comprar outra vez": as mesmas variantes e personalizacoes voltam ao
 * carrinho, ao preco de hoje. O que ja nao se vende fica de fora, pelo nome.
 */
class ReorderTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
        $this->fakeStripe();
        app(SettingService::class)->set(StoreSettings::KEY_SHOP, 'on');
        RateLimiter::clear('cart');

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'customer');
        // Como no browser: a guarda por omissao continua a `web`.
        Auth::shouldUse('web');
    }

    /** @param  array<string, string>|null  $personalization */
    private function orderOf(Variant $variant, int $qty = 2, ?array $personalization = null, string $name = 'Vaso'): Order
    {
        $order = Order::factory()->paid()->create(['user_id' => $this->user->id, 'order_number' => '2026-0100']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'product_name' => $name,
            'qty' => $qty,
            'unit_price_cents' => 100,
            'line_total_cents' => 100 * $qty,
            'personalization' => $personalization,
        ]);

        return $order;
    }

    /** @param  TestResponse<Response>  $response */
    private function cartToken(TestResponse $response): string
    {
        return (string) $response->getCookie((string) config('shop.cart.cookie'))?->getValue();
    }

    public function test_reorder_adds_the_same_variants_and_personalization(): void
    {
        $product = Product::factory()->create([
            'personalization_fields' => [['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true, 'maxLength' => 10]],
            'personalization_surcharge_cents' => 300,
        ]);
        $variant = Variant::factory()->for($product)->create(['price_cents' => 1500]);
        $order = $this->orderOf($variant, 2, ['nome' => 'Rita']);

        $response = $this->post('/conta/encomendas/'.$order->order_number.'/comprar-outra-vez');

        $response->assertRedirect('/carrinho');
        $token = $this->cartToken($response);
        $this->assertNotSame('', $token);

        $this->withCookie((string) config('shop.cart.cookie'), $token)->get('/carrinho')
            ->assertInertia(fn (Assert $page) => $page
                ->has('cart.lines', 1)
                ->where('cart.lines.0.variantId', $variant->id)
                ->where('cart.lines.0.qty', 2)
                ->where('cart.lines.0.personalization.0.value', 'Rita'));
    }

    public function test_reorder_skips_archived_sold_out_and_unpriced_variants_by_name(): void
    {
        $good = Variant::factory()->create(['price_cents' => 1000]);
        $archived = Variant::factory()->for(Product::factory()->archived()->create())->create();
        $soldOut = Variant::factory()->create(['stock' => 0]);
        $unpriced = Variant::factory()->create(['price_cents' => 0]);

        $order = $this->orderOf($good, 1, null, 'Vaso bom');
        foreach ([[$archived, 'Vaso arquivado'], [$soldOut, 'Vaso esgotado'], [$unpriced, 'Vaso sem preco']] as [$variant, $name]) {
            OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $variant->id, 'product_name' => $name, 'qty' => 1]);
        }
        OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => null, 'product_name' => 'Vaso apagado', 'qty' => 1]);

        $response = $this->post('/conta/encomendas/'.$order->order_number.'/comprar-outra-vez');

        $response->assertRedirect('/carrinho');
        $toast = (array) session('inertia.flash_data.toast');
        $this->assertSame('warning', $toast['type']);
        foreach (['Vaso arquivado', 'Vaso esgotado', 'Vaso sem preco', 'Vaso apagado'] as $name) {
            $this->assertStringContainsString($name, $toast['message']);
        }
        $this->assertStringNotContainsString('Vaso bom', $toast['message']);

        $this->withCookie((string) config('shop.cart.cookie'), $this->cartToken($response))->get('/carrinho')
            ->assertInertia(fn (Assert $page) => $page->has('cart.lines', 1)->where('cart.lines.0.variantId', $good->id));
    }

    public function test_reorder_with_nothing_buyable_creates_no_cart(): void
    {
        $soldOut = Variant::factory()->create(['stock' => 0]);
        $order = $this->orderOf($soldOut, 1, null, 'Vaso esgotado');

        $response = $this->from('/conta/encomendas/'.$order->order_number)
            ->post('/conta/encomendas/'.$order->order_number.'/comprar-outra-vez');

        $response->assertRedirect('/conta/encomendas/'.$order->order_number);
        $this->assertSame('', $this->cartToken($response));
        $this->assertSame(0, Cart::query()->count());
    }

    public function test_reorder_uses_todays_price(): void
    {
        $variant = Variant::factory()->create(['price_cents' => 2500]);
        $order = $this->orderOf($variant, 1);

        $response = $this->post('/conta/encomendas/'.$order->order_number.'/comprar-outra-vez');

        // A encomenda guardou 1,00 €; o carrinho cota com o preco da variante.
        $this->withCookie((string) config('shop.cart.cookie'), $this->cartToken($response))->get('/carrinho')
            ->assertInertia(fn (Assert $page) => $page->where('cart.lines.0.unitCents', 2500));
    }
}
