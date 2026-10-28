<?php

namespace Tests\Feature\Store;

use App\Http\Controllers\Store\SitemapController;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\PageBuilder\Pages;
use App\Services\SettingService;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SitemapAndErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
        Cache::forget(SitemapController::CACHE_KEY);
    }

    public function test_the_sitemap_lists_what_is_public_in_every_language(): void
    {
        $product = Product::factory()->create(['slug' => 'vaso']);
        Variant::factory()->for($product)->create();
        Product::factory()->create(['slug' => 'rascunho', 'status' => 'draft']);
        app(Pages::class)->system('home');
        app(Pages::class)->system('about');
        app(Pages::class)->system('terms');

        $xml = $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('<loc>'.url('/produtos/vaso').'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.url('/en/produtos/vaso').'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.url('/sobre').'</loc>', $xml);
        $this->assertStringContainsString('hreflang="fr" href="'.url('/fr/sobre').'"', $xml);
        $this->assertStringNotContainsString('rascunho', $xml);
        // As legais nascem em rascunho.
        $this->assertStringNotContainsString('/termos', $xml);
    }

    public function test_switched_off_languages_stay_out_of_the_sitemap(): void
    {
        app(SettingService::class)->set(StoreSettings::KEY_LOCALES, ['pt', 'en']);
        app(Pages::class)->system('about');

        $xml = $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString(url('/en/sobre'), $xml);
        $this->assertStringNotContainsString(url('/fr/sobre'), $xml);
    }

    public function test_no_sitemap_while_the_store_is_closed(): void
    {
        config(['access.store_open' => false]);

        $this->get('/sitemap.xml')->assertNotFound();
    }

    public function test_store_404s_use_the_store_layout_in_the_right_language(): void
    {
        $this->get('/produtos/nao-existe')->assertNotFound()->assertInertia(fn (Assert $page) => $page
            ->component('store/error')
            ->where('status', 404)
            ->where('locale.current', 'pt')
            ->has('storefront.menus.header'));

        $this->get('/en/nada/aqui')->assertNotFound()->assertInertia(fn (Assert $page) => $page
            ->component('store/error')
            ->where('locale.current', 'en'));
    }

    public function test_backoffice_and_json_errors_are_left_alone(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/nao-existe')->assertNotFound()->assertDontSee('store/error');
        $this->getJson('/produtos/nao-existe')->assertNotFound()->assertJsonMissing(['component' => 'store/error']);
    }

    public function test_a_closed_store_keeps_the_plain_404(): void
    {
        config(['access.store_open' => false]);

        $this->get('/qualquer/coisa')->assertNotFound()->assertDontSee('store/error');
    }
}
