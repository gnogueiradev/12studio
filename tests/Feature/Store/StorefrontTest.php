<?php

namespace Tests\Feature\Store;

use App\Models\Category;
use App\Models\Menu;
use App\Models\User;
use App\Services\SettingService;
use App\Services\StoreMenus;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * O que o StoreLayout recebe em todas as paginas da montra: menus ja no
 * idioma da pagina, contactos e redes preenchidos, categorias do rodape.
 */
class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
    }

    public function test_the_default_header_is_translated_and_localized(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('storefront.menus.header', 4)
            ->where('storefront.menus.header.0', [
                'label' => 'Catálogo',
                'href' => '/produtos',
                'external' => false,
                'children' => [],
            ]));

        $this->get('/en')->assertInertia(fn (Assert $page) => $page
            ->where('storefront.menus.header.0.label', 'Shop')
            ->where('storefront.menus.header.0.href', '/en/produtos'));
    }

    public function test_the_default_footer_links_the_complaints_book(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('storefront.menus.footer', 2)
            ->where('storefront.menus.footer.1.label', 'Legal')
            ->where('storefront.menus.footer.1.children.2', [
                'label' => 'Livro de Reclamações',
                'href' => StoreMenus::COMPLAINTS_BOOK_URL,
                'external' => true,
                'children' => [],
            ]));
    }

    public function test_a_saved_menu_replaces_the_default_and_drops_broken_items(): void
    {
        Menu::query()->create(['location' => 'header', 'items' => [
            ['label' => ['pt' => 'Natal', 'en' => 'Christmas'], 'type' => 'path', 'target' => '/natal'],
            ['label' => ['pt' => 'Só em PT'], 'type' => 'path', 'target' => '/sobre'],
            ['label' => ['pt' => 'Etsy'], 'type' => 'url', 'target' => 'https://www.etsy.com/shop/x'],
            ['label' => ['pt' => 'Email'], 'type' => 'email', 'target' => 'ola@loja.pt'],
            ['label' => ['pt' => 'Mau'], 'type' => 'url', 'target' => 'javascript:alert(1)'],
            ['label' => ['pt' => 'Outro site'], 'type' => 'path', 'target' => '//evil.example'],
            ['label' => ['pt' => 'Outro site'], 'type' => 'path', 'target' => '/\\evil.example'],
            ['label' => ['pt' => ''], 'type' => 'path', 'target' => '/vazio'],
            'lixo',
        ]]);

        $this->get('/en')->assertInertia(fn (Assert $page) => $page
            ->has('storefront.menus.header', 4)
            ->where('storefront.menus.header.0.label', 'Christmas')
            ->where('storefront.menus.header.0.href', '/en/natal')
            // Sem rotulo em ingles, fica o portugues.
            ->where('storefront.menus.header.1.label', 'Só em PT')
            ->where('storefront.menus.header.2.external', true)
            ->where('storefront.menus.header.3.href', 'mailto:ola@loja.pt'));
    }

    public function test_only_filled_contacts_and_networks_are_shared(): void
    {
        $settings = app(SettingService::class);
        $settings->set(StoreSettings::KEY_CONTACT_EMAIL, 'ola@loja.pt');
        $settings->set(StoreSettings::KEY_RESPONSE_HOURS, 24);
        $settings->set(StoreSettings::KEY_SOCIAL, ['instagram' => 'https://instagram.com/loja', 'tiktok' => '']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('storefront.contact', ['email' => 'ola@loja.pt', 'responseHours' => 24])
            ->where('storefront.social', [
                ['network' => 'instagram', 'label' => 'Instagram', 'url' => 'https://instagram.com/loja'],
            ]));
    }

    public function test_the_footer_lists_only_visible_categories(): void
    {
        Category::factory()->create(['name' => 'Vasos', 'slug' => 'vasos', 'status' => 'visible', 'sort_order' => 2]);
        Category::factory()->create(['name' => 'Candeeiros', 'slug' => 'candeeiros', 'status' => 'visible', 'sort_order' => 1]);
        Category::factory()->create(['name' => 'Escondida', 'slug' => 'escondida', 'status' => 'hidden']);
        Category::factory()->create(['name' => 'Arquivada', 'slug' => 'arquivada', 'status' => 'archived']);

        $this->get('/fr')->assertInertia(fn (Assert $page) => $page
            ->where('storefront.categories', [
                ['label' => 'Candeeiros', 'href' => '/fr/categoria/candeeiros'],
                ['label' => 'Vasos', 'href' => '/fr/categoria/vasos'],
            ]));
    }

    public function test_the_backoffice_does_not_get_the_storefront(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->missing('storefront'));
    }
}
