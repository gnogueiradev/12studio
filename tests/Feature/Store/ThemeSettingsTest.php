<?php

namespace Tests\Feature\Store;

use App\Models\User;
use App\Services\SettingService;
use App\Services\ThemeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O tema da montra sai das definicoes para um <style> no <head>: so quando o
 * dono o mudou, so nas paginas da montra, e so com valores que nao conseguem
 * fugir do CSS.
 */
class ThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
    }

    private function saveTheme(array $theme): void
    {
        app(SettingService::class)->set(ThemeSettings::KEY, $theme);
    }

    public function test_the_original_theme_injects_nothing(): void
    {
        $this->assertSame('', app(ThemeSettings::class)->css());

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('id="store-theme"', false)
            ->assertSee('background-color: #faf8f5', false);
    }

    public function test_a_custom_theme_reaches_the_store_pages(): void
    {
        $this->saveTheme([
            'light' => ['background' => '#FFFFFF', 'primary' => '#1d4ed8'],
            'radius' => 'round',
        ]);

        $css = app(ThemeSettings::class)->css();

        $this->assertStringContainsString('.store-theme{--background:#ffffff;', $css);
        $this->assertStringContainsString('--primary:#1d4ed8;', $css);
        $this->assertStringContainsString('--radius:1rem;', $css);
        // O que nao foi mexido fica com o valor de origem.
        $this->assertStringContainsString('.dark .store-theme,.dark.store-theme{--background:#211e1c;', $css);

        $this->get(route('home'))
            ->assertSee('<style id="store-theme">', false)
            ->assertSee('background-color: #ffffff', false);

        $this->get('/en')->assertSee('<style id="store-theme">', false);
    }

    public function test_the_backoffice_keeps_its_own_look(): void
    {
        $this->saveTheme(['light' => ['background' => '#ffffff']]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertDontSee('id="store-theme"', false)
            ->assertSee('background-color: #FAF8F5', false);
    }

    public function test_values_that_are_not_hex_colours_never_reach_the_page(): void
    {
        $this->saveTheme([
            'light' => [
                'background' => 'red;}body{display:none',
                'primary' => '</style><script>alert(1)</script>',
                'unknown' => '#123456',
            ],
            'radius' => '9999px',
        ]);

        $theme = app(ThemeSettings::class);

        $this->assertFalse($theme->isCustom());
        $this->assertSame('', $theme->css());

        $this->get(route('home'))->assertDontSee('alert(1)', false)->assertDontSee('display:none', false);
    }

    public function test_the_landing_carries_the_theme_scope(): void
    {
        config(['access.store_open' => false]);
        $this->saveTheme(['dark' => ['gold' => '#aa8844']]);

        $this->get(route('home'))->assertSee('<style id="store-theme">', false);
    }
}
