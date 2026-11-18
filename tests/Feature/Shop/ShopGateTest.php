<?php

namespace Tests\Feature\Shop;

use App\Models\User;
use App\Services\SettingService;
use App\Services\StoreSettings;
use App\Shop\ShopGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
