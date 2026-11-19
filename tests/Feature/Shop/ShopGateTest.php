<?php

namespace Tests\Feature\Shop;

use App\Models\User;
use App\Services\SettingService;
use App\Services\StoreSettings;
use App\Shop\ShopGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Stripe\FakesStripe;
use Tests\TestCase;

/**
 * O interruptor "Compras na montra": desligado, so a equipa ou ligado, e so
 * conta quando o Stripe e o segredo do webhook estao configurados.
 */
class ShopGateTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    private function mode(string $mode): void
    {
        app(SettingService::class)->set(StoreSettings::KEY_SHOP, $mode);
    }

    private function gate(): ShopGate
    {
        return app(ShopGate::class);
    }

    public function test_it_is_off_by_default(): void
    {
        $this->fakeStripe();

        $this->assertSame('off', app(StoreSettings::class)->shopMode());
        $this->assertFalse($this->gate()->allows(null));
        $this->assertFalse($this->gate()->allows(User::factory()->admin()->create()));
    }

    public function test_staff_mode_only_lets_admins_in(): void
    {
        $this->fakeStripe();
        $this->mode('staff');

        $this->assertTrue($this->gate()->allows(User::factory()->admin()->create()));
        $this->assertFalse($this->gate()->allows(null));
        $this->assertFalse($this->gate()->allows(User::factory()->create()));
        $this->assertFalse($this->gate()->allows(User::factory()->production()->create()));
    }

    public function test_on_lets_everyone_in(): void
    {
        $this->fakeStripe();
        $this->mode('on');

        $this->assertTrue($this->gate()->allows(null));
    }

    public function test_in_production_a_test_key_keeps_the_shop_for_the_staff(): void
    {
        $this->fakeStripe();
        $this->mode('on');
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertTrue($this->gate()->testMode());
        $this->assertFalse($this->gate()->allows(null));
        $this->assertFalse($this->gate()->allows(User::factory()->create()));
        $this->assertTrue($this->gate()->allows(User::factory()->admin()->create()));
    }

    public function test_in_production_a_live_key_opens_the_shop_to_everyone(): void
    {
        $this->fakeStripe();
        $this->mode('on');
        $this->app->detectEnvironment(fn (): string => 'production');
        // Montada aos bocados, como a do FakesStripe, para o secret scanning.
        config(['stripe.secret' => 'sk_'.'live_'.str_repeat('x', 24)]);

        $this->assertFalse($this->gate()->testMode());
        $this->assertTrue($this->gate()->allows(null));
    }

    public function test_outside_production_a_test_key_is_not_test_mode(): void
    {
        $this->fakeStripe();
        $this->mode('on');

        $this->assertFalse($this->gate()->testMode());
        $this->assertTrue($this->gate()->allows(null));
    }

    public function test_the_settings_page_says_when_stripe_is_in_test_mode(): void
    {
        $this->fakeStripe();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get(route('admin.montra.index'))
            ->assertInertia(fn (Assert $page) => $page->where('shopTestMode', false));

        $this->app->detectEnvironment(fn (): string => 'production');

        $this->actingAs($owner)->get(route('admin.montra.index'))
            ->assertInertia(fn (Assert $page) => $page->where('shopReady', true)->where('shopTestMode', true));
    }

    public function test_without_stripe_or_without_the_webhook_secret_it_behaves_as_off(): void
    {
        $this->fakeStripe();
        $this->mode('on');

        config(['stripe.enabled' => false]);
        $this->assertFalse($this->gate()->ready());
        $this->assertFalse($this->gate()->allows(null));

        $this->fakeStripe();
        config(['stripe.webhook_secret' => null]);
        $this->assertFalse($this->gate()->ready());
        $this->assertFalse($this->gate()->allows(null));
    }

    public function test_the_owner_saves_the_mode_in_store_settings(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->patch(route('admin.montra.update'), ['open' => true, 'shop' => 'staff'])
            ->assertSessionHasNoErrors();

        $this->assertSame('staff', app(StoreSettings::class)->shopMode());

        $this->actingAs($owner)
            ->patch(route('admin.montra.update'), ['open' => true, 'shop' => 'talvez'])
            ->assertSessionHasErrors('shop');

        $this->assertSame('staff', app(StoreSettings::class)->shopMode());
    }
}
