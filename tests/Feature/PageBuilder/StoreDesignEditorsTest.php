<?php

namespace Tests\Feature\PageBuilder;

use App\Models\Media;
use App\Models\Menu;
use App\Models\User;
use App\PageBuilder\PageBuilderService;
use App\Services\SettingService;
use App\Services\ThemeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Os outros editores do construtor: menus, tema (so o dono) e a biblioteca
 * de imagens.
 */
class StoreDesignEditorsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function theme(array $light = []): array
    {
        return [
            'light' => $light + ThemeSettings::DEFAULTS['light'],
            'dark' => ThemeSettings::DEFAULTS['dark'],
            'radius' => 'round',
            'body_font' => 'inter',
            'heading_font' => 'fraunces',
        ];
    }

    public function test_the_menu_editor_shows_the_default_menus_with_labels_in_every_language(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.montra.menus.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/montra/menus')
                ->where('menus.header.0.target', '/produtos')
                ->where('menus.header.0.label.pt', 'Catálogo')
                ->has('menus.header.0.label.en')
                ->has('links'));
    }

    public function test_a_saved_header_reaches_the_store(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.montra.menus.update', 'header'), ['items' => [
                ['label' => ['pt' => 'Natal', 'en' => 'Christmas'], 'type' => 'path', 'target' => '/natal'],
                ['label' => ['pt' => 'Etsy'], 'type' => 'url', 'target' => 'https://etsy.com/shop/x'],
            ]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->get('/en/produtos')->assertInertia(fn (Assert $page) => $page
            ->where('storefront.menus.header.0.label', 'Christmas')
            ->where('storefront.menus.header.0.href', '/en/natal')
            ->where('storefront.menus.header.1.label', 'Etsy'));
    }

    public function test_menus_refuse_unsafe_links_and_missing_labels(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.montra.menus.update', 'header'), ['items' => [
                ['label' => ['pt' => 'Mau'], 'type' => 'url', 'target' => 'javascript:alert(1)'],
                ['label' => ['en' => 'Sem PT'], 'type' => 'path', 'target' => '//outro.site'],
            ]])
            ->assertSessionHasErrorsIn('header', ['items.0.target', 'items.1.label.pt', 'items.1.target']);

        $this->assertNull(Menu::query()->where('location', 'header')->first());
    }

    public function test_a_menu_goes_back_to_the_default(): void
    {
        Menu::query()->create(['location' => 'footer', 'items' => []]);

        $this->actingAs($this->admin)->delete(route('admin.montra.menus.reset', 'footer'))->assertRedirect();

        $this->assertNull(Menu::query()->where('location', 'footer')->first());
    }

    public function test_only_the_owner_edits_the_theme(): void
    {
        $this->actingAs($this->admin)->get(route('admin.montra.tema.edit'))->assertForbidden();
        $this->actingAs($this->admin)->put(route('admin.montra.tema.update'), $this->theme())->assertForbidden();

        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get(route('admin.montra.tema.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/montra/tema')->has('fonts'));

        $this->actingAs($owner)
            ->put(route('admin.montra.tema.update'), $this->theme(['primary' => '#1d4ed8']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('#1d4ed8', app(ThemeSettings::class)->values()['light']['primary']);
        $this->assertSame('inter', app(ThemeSettings::class)->values()['body_font']);
    }

    public function test_a_theme_below_3_to_1_contrast_is_refused(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->put(route('admin.montra.tema.update'), $this->theme(['foreground' => '#f5f3f0']))
            ->assertSessionHasErrors('light.foreground');

        $this->assertNull(app(SettingService::class)->get(ThemeSettings::KEY));
    }

    public function test_theme_preview_returns_css_and_reset_goes_back_to_the_original(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->postJson(route('admin.montra.tema.preview'), $this->theme(['primary' => '#1d4ed8']))
            ->assertOk()
            ->assertJsonPath('css', fn (string $css) => str_contains($css, '--primary:#1d4ed8'));

        app(SettingService::class)->set(ThemeSettings::KEY, $this->theme());

        $this->actingAs($owner)->delete(route('admin.montra.tema.reset'))->assertRedirect();
        $this->assertFalse(app(ThemeSettings::class)->isCustom());
    }

    public function test_images_are_uploaded_to_the_library(): void
    {
        Storage::fake('public');
        Queue::fake();

        $this->actingAs($this->admin)
            ->postJson(route('admin.montra.media.store'), [
                'files' => [UploadedFile::fake()->image('hero.jpg', 1600, 900)],
                'alt' => 'Vasos na janela',
            ])
            ->assertCreated()
            ->assertJsonPath('data.0.alt', 'Vasos na janela')
            ->assertJsonPath('data.0.width', 1600);

        $media = Media::query()->firstOrFail();
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_svg_uploads_are_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->postJson(route('admin.montra.media.store'), [
                'files' => [UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
            ])
            ->assertUnprocessable();

        $this->assertSame(0, Media::query()->count());
    }

    public function test_an_image_in_use_is_not_deleted(): void
    {
        Storage::fake('public');
        $media = Media::factory()->create();

        $page = app(PageBuilderService::class)->createLanding(['name' => 'Natal', 'slug' => 'natal']);
        app(PageBuilderService::class)->saveDraft($page, [['type' => 'text_image', 'settings' => ['image' => $media->id], 'content' => ['pt' => ['title' => 'Olá']]]]);

        $this->actingAs($this->admin)
            ->delete(route('admin.montra.media.destroy', $media))
            ->assertSessionHasErrors('media');

        $this->assertNotNull($media->fresh());

        app(PageBuilderService::class)->saveDraft($page, []);

        $this->actingAs($this->admin)->delete(route('admin.montra.media.destroy', $media))->assertSessionHasNoErrors();
        $this->assertNull($media->fresh());
    }

    public function test_the_picker_lists_and_searches_the_library(): void
    {
        Media::factory()->create(['original_name' => 'natal.jpg']);
        Media::factory()->create(['original_name' => 'verao.jpg']);

        $this->actingAs($this->admin)
            ->getJson(route('admin.montra.media.lista', ['q' => 'natal']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'natal.jpg');

        $this->actingAs($this->admin)
            ->get(route('admin.montra.media.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/montra/media')->has('media.data', 2));
    }
}
