<?php

namespace Tests\Feature\Store;

use App\Models\User;
use App\Services\SettingService;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Os idiomas da montra: o de omissao na raiz, os outros com prefixo, e cada
 * loja escolhe quais tem ligados.
 */
class StoreLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
    }

    public function test_the_root_is_in_the_default_language(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<html lang="pt-PT"', false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale.current', 'pt')
                ->where('locale.default', 'pt')
                ->has('locale.available', 4)
                ->where('i18n.nav.catalog', 'Catálogo'));
    }

    public function test_a_prefix_switches_the_language(): void
    {
        $this->get('/en')
            ->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/page')
                ->where('locale.current', 'en')
                ->where('i18n.nav.catalog', 'Shop'));

        $this->get('/fr')->assertInertia(fn (Assert $page) => $page
            ->where('i18n.nav.custom', 'Sur mesure'));
    }

    public function test_the_localized_route_has_its_own_name(): void
    {
        $this->assertSame(url('/es'), route('i18n.home', ['locale' => 'es']));
    }

    public function test_unknown_prefixes_and_the_default_as_prefix_are_not_found(): void
    {
        $this->get('/de')->assertNotFound();
        $this->get('/pt')->assertNotFound();
    }

    public function test_a_language_switched_off_in_this_store_is_not_found(): void
    {
        app(SettingService::class)->set(StoreSettings::KEY_LOCALES, ['pt', 'en']);

        $this->get('/fr')->assertNotFound();
        $this->get('/en')->assertOk();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('locale.available', 2)
            ->where('locale.available.1.code', 'en'));
    }

    public function test_the_default_language_cannot_be_switched_off(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('admin.montra.update'), [
                'open' => true,
                'locales' => ['en'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(['pt', 'en'], app(StoreSettings::class)->locales());
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('admin.montra.update'), [
                'open' => true,
                'locales' => ['pt', 'de'],
            ])
            ->assertSessionHasErrors('locales.1');
    }

    public function test_a_save_without_languages_keeps_them(): void
    {
        app(SettingService::class)->set(StoreSettings::KEY_LOCALES, ['pt', 'es']);

        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('admin.montra.update'), ['open' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(['pt', 'es'], app(StoreSettings::class)->locales());
    }

    public function test_the_closed_store_answers_every_language_with_the_landing(): void
    {
        config(['access.store_open' => false]);

        $this->get('/en')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('coming-soon'));
    }

    public function test_the_backoffice_does_not_get_the_store_texts(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('i18n')
                ->missing('locale'));
    }
}
