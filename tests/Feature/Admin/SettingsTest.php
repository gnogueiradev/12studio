<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use App\Models\Variant;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * config('shop.currency') deixa de ser a autoridade mas continua a ser o
     * valor por omissao enquanto ninguem tiver mexido nas definicoes.
     */
    public function test_the_currency_falls_back_to_the_config(): void
    {
        $this->assertSame(
            config('shop.currency'),
            app(SettingService::class)->currency(),
        );
    }

    /**
     * O HandleInertiaRequests le as definicoes em TODOS os pedidos, incluindo
     * num deploy antes de as migracoes correrem. Um simbolo de moeda nao pode
     * deitar a loja inteira abaixo — sem tabela, cai no valor por omissao.
     */
    public function test_a_missing_settings_table_does_not_break_the_page(): void
    {
        Schema::drop('settings');

        $this->assertSame(
            config('shop.currency'),
            app(SettingService::class)->currency(),
        );
    }

    public function test_the_admin_can_change_the_currency(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.update'), ['currency' => 'GBP'])
            ->assertRedirect(route('admin.definicoes.index'));

        $this->assertDatabaseHas('settings', ['key' => 'currency']);
        $this->assertSame('GBP', app(SettingService::class)->currency());
    }

    public function test_an_unknown_currency_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.update'), ['currency' => 'XYZ'])
            ->assertSessionHasErrors('currency');

        $this->assertDatabaseCount('settings', 0);
    }

    /**
     * A moeda vai partilhada em TODAS as paginas — e o lib/money.ts que a
     * usa para formatar, em vez do EUR fixo que tinha antes.
     */
    public function test_the_currency_is_shared_with_every_page(): void
    {
        Setting::query()->create(['key' => 'currency', 'value' => 'GBP']);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency', 'GBP'));
    }

    public function test_changing_the_currency_does_not_touch_any_price(): void
    {
        $variant = Variant::factory()->create(['price_cents' => 2490]);

        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.update'), ['currency' => 'USD']);

        $this->assertSame(2490, $variant->refresh()->price_cents);
    }

    public function test_non_admins_cannot_change_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('admin.definicoes.update'), ['currency' => 'GBP'])
            ->assertForbidden();

        $this->assertDatabaseCount('settings', 0);
    }
}
