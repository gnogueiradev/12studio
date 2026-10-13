<?php

namespace Tests\Feature\Etsy;

use App\Etsy\EtsySettings;
use App\Models\Category;
use App\Models\EtsyListing;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Etsy -> Loja: os textos publicos da loja, as seccoes, e a que seccao vai
 * cada categoria do 12studio. As politicas de devolucao vivem no ecra dos
 * envios, mas sao da loja e testam-se aqui.
 */
class EtsyShopTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureEtsy();
        $this->connectEtsy();
        $this->fakeEtsyShopConfig();

        $this->admin = User::factory()->admin()->create();
    }

    private function written(): Request
    {
        return $this->etsyRequests()->filter(fn (Request $request): bool => $request->method() !== 'GET')->sole();
    }

    private function writes(): int
    {
        return $this->etsyRequests()->filter(fn (Request $request): bool => $request->method() !== 'GET')->count();
    }

    // ── A pagina ───────────────────────────────────────────────────────────

    public function test_the_page_shows_the_shop_texts_the_sections_and_the_category_map(): void
    {
        $vases = Category::factory()->create(['name' => 'Vasos']);
        Category::factory()->create(['name' => 'Arquivada', 'status' => 'archived']);
        app(EtsySettings::class)->save(['section_map' => [$vases->id => 31]]);

        $this->actingAs($this->admin)
            ->get(route('admin.etsy.loja.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/etsy/loja')
                ->where('connected', true)
                ->where('shop.title', 'Peças impressas em 3D')
                ->where('shop.announcement', 'Bem-vindos!')
                ->has('sections', 2)
                ->where('sections.0.id', 31)
                ->where('sections.0.title', 'Vasos')
                ->where('sections.0.activeListings', 4)
                // So as categorias em uso: uma arquivada nao recebe anuncios.
                ->has('categories', 1)
                ->where('categories.0.name', 'Vasos')
                ->where('categories.0.sectionId', 31));
    }

    // ── Textos ─────────────────────────────────────────────────────────────

    public function test_the_shop_texts_are_sent_as_a_form(): void
    {
        Http::fake(['etsy.test/*' => Http::response(['shop_id' => 4242])]);

        $this->actingAs($this->admin)
            ->from(route('admin.etsy.loja.index'))
            ->patch(route('admin.etsy.loja.textos'), [
                'title' => 'Peças impressas em 3D, feitas no Porto',
                'announcement' => 'Envios todas as segundas.',
                'sale_message' => 'Obrigado!',
                'policy_additional' => '',
            ])
            ->assertRedirect(route('admin.etsy.loja.index'))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        $request = $this->written();
        $this->assertSame('PUT', $request->method());
        $this->assertStringEndsWith('/shops/4242', $request->url());
        $this->assertStringStartsWith('application/x-www-form-urlencoded', $request->header('Content-Type')[0]);
        $this->assertSame('Envios todas as segundas.', $request['announcement']);
        // Vazio e para limpar o texto la, nao para o deixar como estava.
        $this->assertSame('', $request['policy_additional']);
    }

    public function test_the_shop_texts_are_read_again_after_being_changed(): void
    {
        // A primeira visita deixa os textos lembrados; o fake() a seguir
        // recomeca a contagem dos pedidos.
        $this->actingAs($this->admin)->get(route('admin.etsy.loja.index'));
        Http::fake(['etsy.test/*' => Http::response(['shop_id' => 4242])]);

        $this->actingAs($this->admin)->patch(route('admin.etsy.loja.textos'), ['title' => 'Outro', 'announcement' => 'A']);
        $this->actingAs($this->admin)->get(route('admin.etsy.loja.index'));

        // Com a cache por limpar, a pagina mostrava o texto antigo e esta
        // leitura nao acontecia.
        $this->assertCount(1, $this->etsyRequests('/shops/4242')->filter(
            fn (Request $r): bool => $r->method() === 'GET' && str_ends_with($r->url(), '/shops/4242'),
        ));
    }

    // ── Seccoes ────────────────────────────────────────────────────────────

    public function test_sections_are_created_and_renamed(): void
    {
        Http::fake(['etsy.test/*' => Http::response(['shop_section_id' => 33, 'title' => 'Candeeiros'])]);

        $this->actingAs($this->admin)->post(route('admin.etsy.loja.seccoes.guardar'), ['title' => 'Candeeiros'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.etsy.loja.seccoes.guardar'), ['section_id' => 32, 'title' => 'Suportes de mesa'])->assertSessionHasNoErrors();

        [$created, $renamed] = $this->etsyRequests()->filter(fn (Request $r): bool => $r->method() !== 'GET')->values()->all();

        $this->assertSame('POST', $created->method());
        $this->assertStringEndsWith('/shops/4242/sections', $created->url());
        $this->assertSame('Candeeiros', $created['title']);

        $this->assertSame('PUT', $renamed->method());
        $this->assertStringEndsWith('/shops/4242/sections/32', $renamed->url());
        $this->assertSame('Suportes de mesa', $renamed['title']);
    }

    public function test_deleting_a_section_forgets_it_everywhere_here(): void
    {
        $category = Category::factory()->create();
        app(EtsySettings::class)->save(['section_map' => [$category->id => 31]]);
        $listing = EtsyListing::query()->create([
            'product_id' => Product::factory()->create()->id, 'etsy_listing_id' => 7001, 'state' => 'draft', 'shop_section_id' => 31,
        ]);
        Http::fake(['etsy.test/*' => Http::response('', 204)]);

        $this->actingAs($this->admin)
            ->delete(route('admin.etsy.loja.seccoes.apagar', 31))
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        $this->assertStringEndsWith('/shops/4242/sections/31', $this->written()->url());
        // Na Etsy os anuncios ficam sem seccao; ca nao pode ficar a apontar
        // para uma seccao que ja nao existe, senao o proximo envio falhava.
        $this->assertNull($listing->refresh()->shop_section_id);
        $this->assertSame([], app(EtsySettings::class)->sectionMap());
    }

    public function test_the_category_map_is_kept_here_and_writes_nothing_to_etsy(): void
    {
        $vases = Category::factory()->create();
        $stands = Category::factory()->create();

        $this->actingAs($this->admin)
            ->from(route('admin.etsy.loja.index'))
            ->patch(route('admin.etsy.loja.categorias'), ['sections' => [$vases->id => 31, $stands->id => null]])
            ->assertRedirect(route('admin.etsy.loja.index'));

        $this->assertSame([$vases->id => 31], app(EtsySettings::class)->sectionMap());
        $this->assertSame(0, $this->writes());
    }

    // ── Devolucoes ─────────────────────────────────────────────────────────

    public function test_return_policies_are_created_and_edited(): void
    {
        Http::fake(['etsy.test/*' => Http::response(['return_policy_id' => 902])]);

        $this->actingAs($this->admin)
            ->post(route('admin.etsy.envios.devolucoes.guardar'), ['accepts_returns' => true, 'accepts_exchanges' => true, 'return_deadline' => 30])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)
            ->post(route('admin.etsy.envios.devolucoes.guardar'), ['policy_id' => 901, 'accepts_returns' => false, 'accepts_exchanges' => false])
            ->assertSessionHasNoErrors();

        [$created, $updated] = $this->etsyRequests()->filter(fn (Request $r): bool => $r->method() !== 'GET')->values()->all();

        $this->assertSame('POST', $created->method());
        $this->assertStringEndsWith('/shops/4242/policies/return', $created->url());
        $this->assertSame('true', $created['accepts_returns']);
        $this->assertSame('30', $created['return_deadline']);

        $this->assertSame('PUT', $updated->method());
        $this->assertStringEndsWith('/policies/return/901', $updated->url());
        $this->assertSame('false', $updated['accepts_returns']);
        // Sem devolucoes nem trocas nao ha prazo para mandar.
        $this->assertArrayNotHasKey('return_deadline', $updated->data());
    }

    public function test_accepting_returns_needs_a_deadline(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.etsy.envios.devolucoes.guardar'), ['accepts_returns' => true, 'accepts_exchanges' => false])
            ->assertSessionHasErrors('return_deadline');

        $this->assertSame(0, $this->writes());
    }

    public function test_a_return_policy_in_use_is_not_deleted(): void
    {
        EtsyListing::query()->create([
            'product_id' => Product::factory()->create(['name' => 'Vaso Espiral'])->id,
            'etsy_listing_id' => 7001, 'state' => 'active', 'return_policy_id' => 901,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.etsy.envios.devolucoes.apagar', 901))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');

        $this->assertSame(0, $this->writes());
    }

    public function test_only_admins_touch_the_shop(): void
    {
        foreach ([User::factory()->create(), User::factory()->production()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.etsy.loja.index'))->assertForbidden();
            $this->actingAs($user)->patch(route('admin.etsy.loja.textos'), ['title' => 'X'])->assertForbidden();
            $this->actingAs($user)->delete(route('admin.etsy.loja.seccoes.apagar', 31))->assertForbidden();
        }

        $this->assertSame(0, $this->writes());
    }
}
