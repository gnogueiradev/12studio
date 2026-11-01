<?php

namespace Tests\Feature\Mcp;

use App\Mcp\ProductImageUploads;
use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Write\Images\ProductImageDeleteTool;
use App\Mcp\Tools\Write\Images\ProductImageReorderTool;
use App\Mcp\Tools\Write\Images\ProductImageSetPrimaryTool;
use App\Mcp\Tools\Write\Images\ProductImageUpdateTool;
use App\Mcp\Tools\Write\Images\ProductImageUploadUrlTool;
use App\Models\McpActivity;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Fotografias pelo Claude: o link de upload de uso unico e a gestao da
 * galeria (texto alternativo, principal, ordem, apagar).
 */
class ProductImageToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->admin = User::factory()->admin()->create();
        $this->product = Product::factory()->create();
    }

    private function writer(): PendingTestResponse
    {
        Passport::actingAs($this->admin, ['mcp:read', 'mcp:write']);

        return StudioServer::actingAs($this->admin);
    }

    private function reader(): PendingTestResponse
    {
        Passport::actingAs($this->admin, ['mcp:read']);

        return StudioServer::actingAs($this->admin);
    }

    private function link(?string $alt = null, bool $primary = false, ?Product $product = null): string
    {
        return app(ProductImageUploads::class)->issue($product ?? $this->product, $this->admin, $alt, $primary)['url'];
    }

    private function photo(string $name = 'foto.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 800, 800);
    }

    private function image(array $attributes = []): ProductImage
    {
        return ProductImage::factory()->for($this->product)->create($attributes);
    }

    // ── Permissoes ─────────────────────────────────────────────────────────

    public function test_a_read_only_key_cannot_touch_images(): void
    {
        $this->reader()->tool(ProductImageUploadUrlTool::class, ['product_id' => $this->product->id])
            ->assertNotRegistered();
        $this->reader()->tool(ProductImageDeleteTool::class, ['image_id' => 1, 'confirm' => true])
            ->assertNotRegistered();
    }

    // ── Link de upload ─────────────────────────────────────────────────────

    public function test_the_tool_hands_out_an_upload_link(): void
    {
        $this->writer()->tool(ProductImageUploadUrlTool::class, ['product_id' => $this->product->id])
            ->assertOk()
            ->assertSee('/mcp/uploads/imagens/')
            ->assertSee('signature=');

        // O link nao e uma fotografia: nada no disco, nada na galeria.
        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_no_link_for_a_deleted_product(): void
    {
        $this->product->delete();

        $this->writer()->tool(ProductImageUploadUrlTool::class, ['product_id' => $this->product->id])
            ->assertHasErrors(['Produto não encontrado.']);
    }

    public function test_uploading_to_the_link_adds_the_photo_and_the_first_is_primary(): void
    {
        $this->post($this->link('Vaso de lado'), ['image' => $this->photo()])
            ->assertCreated()
            ->assertJsonPath('image.is_primary', true)
            ->assertJsonPath('image.alt', 'Vaso de lado');

        $image = ProductImage::query()->sole();
        $this->assertSame($this->product->id, $image->product_id);
        Storage::disk('public')->assertExists($image->path);

        $this->assertDatabaseHas('mcp_activity', [
            'user_id' => $this->admin->id,
            'tool' => 'product_image_upload',
            'result' => McpActivity::RESULT_OK,
        ]);
    }

    public function test_make_primary_takes_over_from_the_old_primary(): void
    {
        $old = $this->image(['is_primary' => true]);

        $this->post($this->link(primary: true), ['image' => $this->photo()])->assertCreated();

        $this->assertFalse($old->refresh()->is_primary);
        $this->assertTrue(ProductImage::query()->whereKeyNot($old->id)->sole()->is_primary);
    }

    public function test_a_link_works_only_once(): void
    {
        $url = $this->link();

        $this->post($url, ['image' => $this->photo()])->assertCreated();
        $this->post($url, ['image' => $this->photo('b.jpg')])->assertStatus(410);

        $this->assertDatabaseCount('product_images', 1);
    }

    public function test_a_tampered_or_unsigned_link_is_refused(): void
    {
        $url = $this->link();
        $unsigned = strtok($url, '?');

        $this->post($unsigned, ['image' => $this->photo()])->assertForbidden();
        $this->post($url.'x', ['image' => $this->photo()])->assertForbidden();

        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_an_expired_link_is_refused(): void
    {
        $url = $this->link();

        $this->travel(ProductImageUploads::TTL_MINUTES + 1)->minutes();

        $this->post($url, ['image' => $this->photo()])->assertForbidden();
        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_an_invalid_file_does_not_burn_the_link(): void
    {
        $url = $this->link();

        $this->post($url, ['image' => UploadedFile::fake()->create('notas.pdf', 10, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Só JPG, PNG ou WEBP.'));

        $this->post($url, ['image' => UploadedFile::fake()->image('grande.jpg')->size(6000)])
            ->assertStatus(422);

        // Com o ficheiro certo, o mesmo link ainda serve.
        $this->post($url, ['image' => $this->photo()])->assertCreated();
    }

    public function test_a_disabled_admin_cannot_use_a_link_issued_before(): void
    {
        $url = $this->link();
        $this->admin->forceFill(['disabled_at' => now()])->save();

        $this->post($url, ['image' => $this->photo()])->assertForbidden();
        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_a_product_deleted_after_the_link_gets_nothing(): void
    {
        $url = $this->link();
        $this->product->delete();

        $this->post($url, ['image' => $this->photo()])->assertNotFound();
        $this->assertDatabaseCount('product_images', 0);
    }

    // ── Galeria ────────────────────────────────────────────────────────────

    public function test_the_alt_text_is_edited(): void
    {
        $image = $this->image();

        $this->writer()->tool(ProductImageUpdateTool::class, ['image_id' => $image->id, 'alt' => 'Vista de cima'])
            ->assertOk();

        $this->assertSame('Vista de cima', $image->refresh()->alt);
    }

    public function test_set_primary_moves_the_primary(): void
    {
        $first = $this->image(['is_primary' => true, 'sort_order' => 1]);
        $second = $this->image(['sort_order' => 2]);

        $this->writer()->tool(ProductImageSetPrimaryTool::class, ['image_id' => $second->id])->assertOk();

        $this->assertFalse($first->refresh()->is_primary);
        $this->assertTrue($second->refresh()->is_primary);
    }

    public function test_reorder_needs_the_whole_gallery(): void
    {
        $a = $this->image(['sort_order' => 1]);
        $b = $this->image(['sort_order' => 2]);
        $c = $this->image(['sort_order' => 3]);

        $this->writer()->tool(ProductImageReorderTool::class, ['product_id' => $this->product->id, 'image_ids' => [$c->id, $a->id]])
            ->assertHasErrors();

        $this->writer()->tool(ProductImageReorderTool::class, ['product_id' => $this->product->id, 'image_ids' => [$c->id, $a->id, $b->id]])
            ->assertOk();

        $this->assertSame([$c->id, $a->id, $b->id], $this->product->images()->pluck('id')->all());
    }

    public function test_images_of_a_deleted_product_are_frozen(): void
    {
        $image = $this->image();
        $this->product->delete();

        $this->writer()->tool(ProductImageUpdateTool::class, ['image_id' => $image->id, 'alt' => 'x'])
            ->assertHasErrors(['O produto desta fotografia foi apagado. Recupera-o primeiro com product_restore.']);
    }

    // ── Apagar ─────────────────────────────────────────────────────────────

    public function test_delete_asks_first(): void
    {
        $image = $this->image(['is_primary' => true]);

        $this->writer()->tool(ProductImageDeleteTool::class, ['image_id' => $image->id])
            ->assertHasErrors()
            ->assertSee('não se recupera');

        $this->assertModelExists($image);
    }

    public function test_confirmed_delete_removes_the_file_and_promotes_the_next(): void
    {
        Storage::disk('public')->put('products/a.jpg', 'x');
        $primary = $this->image(['is_primary' => true, 'sort_order' => 1, 'path' => 'products/a.jpg']);
        $next = $this->image(['sort_order' => 2]);

        $this->writer()->tool(ProductImageDeleteTool::class, ['image_id' => $primary->id, 'confirm' => true])
            ->assertOk()
            ->assertSee('passou a principal');

        $this->assertModelMissing($primary);
        Storage::disk('public')->assertMissing('products/a.jpg');
        $this->assertTrue($next->refresh()->is_primary);
    }
}
