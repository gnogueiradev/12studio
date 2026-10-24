<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Definicoes -> Montra: abrir ou fechar a loja, e os contactos e redes que a
 * montra mostra. Vivem na BD e nao no .env, como a marca — cada loja tem os
 * seus.
 */
class StoreSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_replace_recursive([
            'open' => false,
            'contact_email' => 'ola@oficina.pt',
            'phone' => '+351 912 345 678',
            'address' => 'Rua das Flores 12, Porto',
            'opening_hours' => 'Seg–Sex, 10h–18h',
            'response_hours' => 24,
            'social' => [
                'instagram' => 'https://www.instagram.com/oficina',
                'tiktok' => '',
                'facebook' => '',
                'pinterest' => '',
                'youtube' => '',
                'etsy' => 'https://www.etsy.com/shop/Oficina',
            ],
        ], $overrides);
    }

    private function save(array $overrides = []): TestResponse
    {
        return $this->actingAs(User::factory()->admin()->create())
            ->patch(route('admin.montra.update'), $this->form($overrides));
    }

    public function test_without_settings_the_store_follows_the_config(): void
    {
        config(['access.store_open' => true]);
        $this->assertTrue(app(StoreSettings::class)->isOpen());

        config(['access.store_open' => false]);
        $this->assertFalse(app(StoreSettings::class)->isOpen());
    }

    public function test_the_page_shows_the_current_values(): void
    {
        $this->save()->assertSessionHasNoErrors()->assertRedirect(route('admin.montra.index'));

        $this->get(route('admin.montra.index'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/definicoes/montra')
            ->where('store.open', false)
            ->where('store.contact_email', 'ola@oficina.pt')
            ->where('store.response_hours', 24)
            ->where('store.social.instagram', 'https://www.instagram.com/oficina')
            ->where('store.social.tiktok', '')
            ->has('networks', count(StoreSettings::SOCIAL_NETWORKS)));
    }

    public function test_a_saved_setting_wins_over_the_config(): void
    {
        config(['access.store_open' => false]);

        $this->save(['open' => true])->assertSessionHasNoErrors();

        $this->assertTrue(app(StoreSettings::class)->isOpen());
    }

    public function test_empty_fields_are_stored_empty_and_trimmed(): void
    {
        $this->save([
            'contact_email' => '',
            'phone' => '  ',
            'address' => '  Rua A  ',
            'response_hours' => null,
        ])->assertSessionHasNoErrors();

        $contact = app(StoreSettings::class)->toForm();
        $this->assertSame('', $contact['contact_email']);
        $this->assertSame('', $contact['phone']);
        $this->assertSame('Rua A', $contact['address']);
        $this->assertNull($contact['response_hours']);
    }

    public function test_invalid_values_are_refused(): void
    {
        $this->save([
            'contact_email' => 'isto-nao-e-email',
            'phone' => 'liga-me',
            'response_hours' => 0,
        ])->assertSessionHasErrors(['contact_email', 'phone', 'response_hours']);
    }

    public function test_a_social_link_must_be_https_and_on_its_own_network(): void
    {
        $this->save(['social' => ['instagram' => 'http://www.instagram.com/oficina']])
            ->assertSessionHasErrors('social.instagram');

        $this->save(['social' => ['instagram' => 'https://www.tiktok.com/@oficina']])
            ->assertSessionHasErrors('social.instagram');

        $this->save(['social' => ['tiktok' => 'https://www.tiktok.com/@oficina']])
            ->assertSessionHasNoErrors();
    }

    public function test_unknown_networks_are_ignored(): void
    {
        $this->save(['social' => ['myspace' => 'https://myspace.com/oficina']])->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('myspace', app(StoreSettings::class)->toForm()['social']);
    }

    public function test_only_admins_reach_the_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.montra.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->patch(route('admin.montra.update'), $this->form())
            ->assertForbidden();
    }

    public function test_the_dashboard_reads_the_saved_state(): void
    {
        config(['access.store_open' => false]);
        $this->save(['open' => true]);

        $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('storeOpen', true));
    }
}
