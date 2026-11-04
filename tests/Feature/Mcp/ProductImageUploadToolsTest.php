<?php

namespace Tests\Feature\Mcp;

use App\Jobs\GenerateImageVariants;
use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Write\Images\ProductImageAddTool;
use App\Mcp\Tools\Write\Images\ProductImageUploadAbortTool;
use App\Mcp\Tools\Write\Images\ProductImageUploadChunkTool;
use App\Mcp\Tools\Write\Images\ProductImageUploadFinishTool;
use App\Mcp\Tools\Write\Images\ProductImageUploadStartTool;
use App\Models\McpActivity;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Passport\Passport;
use Mockery;
use Tests\TestCase;

/**
 * Fotografias grandes as fatias: start → chunk × N → finish. O fim tem de dar
 * exatamente o mesmo que o product_image_add com image_base64.
 */
class ProductImageUploadToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();

        $this->admin = User::factory()->admin()->create();
        $this->product = Product::factory()->create(['name' => 'Vaso Espiral', 'status' => 'active']);
    }

    private function writer(?User $user = null): PendingTestResponse
    {
        $user ??= $this->admin;
        Passport::actingAs($user, ['mcp:read', 'mcp:write']);

        return StudioServer::actingAs($user);
    }

    /**
     * Uma fotografia a serio (com ruido, para nao comprimir para nada), gerada
     * pelo GD.
     */
    private function photo(int $width = 900, int $height = 700): string
    {
        $image = imagecreatetruecolor($width, $height);

        for ($i = 0; $i < 4000; $i++) {
            $color = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            imagefilledrectangle($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), (int) $color);
        }

        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * @return list<string>
     */
    private function split(string $bytes, int $size = 12 * 1024): array
    {
        return str_split($bytes, $size);
    }

    /**
     * @param  list<string>  $chunks
     * @param  array<string, mixed>  $extra
     */
    private function start(string $bytes, array $chunks, array $extra = []): string
    {
        $response = $this->writer()->tool(ProductImageUploadStartTool::class, [
            'product_id' => $this->product->id,
            'total_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'chunk_count' => count($chunks),
            ...$extra,
        ])->assertOk();

        return $this->uploadIdFrom($response);
    }

    private function uploadIdFrom(mixed $response): string
    {
        $id = null;

        $response->assertStructuredContent(function (AssertableJson $json) use (&$id): void {
            $id = $json->toArray()['upload_id'] ?? null;
            $json->whereType('upload_id', 'string')->has('expires_at')->etc();
        });

        $this->assertIsString($id);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $id);

        return $id;
    }

    private function chunk(string $uploadId, int $index, string $bytes, ?string $hash = null, ?User $user = null): mixed
    {
        return $this->writer($user)->tool(ProductImageUploadChunkTool::class, [
            'upload_id' => $uploadId,
            'index' => $index,
            'data' => base64_encode($bytes),
            'chunk_sha256' => $hash ?? hash('sha256', $bytes),
        ]);
    }

    private function finish(string $uploadId, ?User $user = null): mixed
    {
        return $this->writer($user)->tool(ProductImageUploadFinishTool::class, ['upload_id' => $uploadId]);
    }

    /**
     * @param  list<string>  $chunks
     */
    private function sendAll(string $uploadId, array $chunks): void
    {
        foreach ($chunks as $index => $chunk) {
            $this->chunk($uploadId, $index, $chunk)->assertOk();
        }
    }

    private function assertNoPendingUploads(): void
    {
        $this->assertSame([], Storage::disk('local')->allFiles('mcp-uploads'));
    }

    private function assertRefusalLogged(string $reason): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => ($context['reason'] ?? null) === $reason)
            ->atLeast()->once();
    }

    // ── caminho feliz ───────────────────────────────────────────────────────

    public function test_start_chunks_finish_creates_the_image_like_product_image_add(): void
    {
        $bytes = $this->photo();
        $chunks = $this->split($bytes);
        $this->assertGreaterThan(2, count($chunks), 'A fotografia de teste devia dar varios pedacos.');

        $id = $this->start($bytes, $chunks, ['alt' => 'De frente']);

        foreach ($chunks as $index => $chunk) {
            $response = $this->chunk($id, $index, $chunk)->assertOk();
        }

        $response->assertSee('"missing":[]');

        $this->finish($id)
            ->assertOk()
            ->assertSee('Fotografia adicionada.')
            ->assertSee('"is_primary":true')
            ->assertSee('"alt":"De frente"');

        $image = ProductImage::query()->firstOrFail();
        Storage::disk('public')->assertExists($image->path);
        $this->assertSame($bytes, Storage::disk('public')->get($image->path));
        $this->assertStringEndsWith('.jpg', $image->path);
        $this->assertTrue($image->is_primary);
        Queue::assertPushed(GenerateImageVariants::class);
        $this->assertNoPendingUploads();
    }

    public function test_finish_matches_product_image_add_for_the_same_bytes(): void
    {
        $bytes = $this->photo();
        $chunks = $this->split($bytes);

        $this->writer()->tool(ProductImageAddTool::class, [
            'product_id' => $this->product->id,
            'image_base64' => base64_encode($bytes),
        ])->assertOk();

        $id = $this->start($bytes, $chunks);
        $this->sendAll($id, $chunks);
        $this->finish($id)->assertOk();

        [$viaAdd, $viaChunks] = ProductImage::query()->orderBy('id')->get()->all();

        $this->assertSame($viaAdd->alt, $viaChunks->alt);
        $this->assertTrue($viaAdd->is_primary);
        $this->assertFalse($viaChunks->is_primary);
        $this->assertSame($viaAdd->sort_order + 1, $viaChunks->sort_order);
        $this->assertSame(Storage::disk('public')->get($viaAdd->path), Storage::disk('public')->get($viaChunks->path));
    }

    public function test_variant_primary_and_position_are_honoured(): void
    {
        $variant = Variant::factory()->for($this->product)->create();
        $first = ProductImage::factory()->create(['product_id' => $this->product->id, 'is_primary' => true, 'sort_order' => 1]);
        $bytes = $this->photo();
        $chunks = $this->split($bytes);

        $id = $this->start($bytes, $chunks, ['variant_id' => $variant->id, 'is_primary' => true, 'position' => 1]);
        $this->sendAll($id, $chunks);
        $this->finish($id)->assertOk();

        $new = ProductImage::query()->latest('id')->firstOrFail();
        $this->assertSame($variant->id, $new->variant_id);
        $this->assertTrue($new->is_primary);
        $this->assertFalse($first->refresh()->is_primary);
        $this->assertSame(1, $new->sort_order);
    }

    public function test_chunks_can_arrive_out_of_order(): void
    {
        $bytes = $this->photo();
        $chunks = $this->split($bytes);
        $id = $this->start($bytes, $chunks);

        foreach (array_reverse($chunks, true) as $index => $chunk) {
            $this->chunk($id, $index, $chunk)->assertOk();
        }

        $this->finish($id)->assertOk();

        $this->assertSame($bytes, Storage::disk('public')->get(ProductImage::query()->firstOrFail()->path));
    }

    public function test_resending_a_chunk_is_idempotent(): void
    {
        $bytes = $this->photo();
        $chunks = $this->split($bytes);
        $id = $this->start($bytes, $chunks);

        $this->chunk($id, 0, $chunks[0])->assertOk()->assertSee('"received":[0]');
        $this->chunk($id, 0, $chunks[0])->assertOk()->assertSee('"received":[0]');

        $this->sendAll($id, $chunks);
        $this->finish($id)->assertOk();

        $this->assertDatabaseCount('product_images', 1);
    }

    public function test_many_chunks_do_not_hit_the_general_write_limit(): void
    {
        $bytes = $this->photo();
        $chunks = $this->split($bytes, 2 * 1024);
        $this->assertGreaterThan(25, count($chunks));

        $id = $this->start($bytes, $chunks);
        $this->sendAll($id, $chunks);

        $this->finish($id)->assertOk();
    }

    public function test_abort_deletes_the_pending_upload(): void
    {
        $bytes = $this->photo();
        $chunks = $this->split($bytes);
        $id = $this->start($bytes, $chunks);
        $this->chunk($id, 0, $chunks[0])->assertOk();

        $this->writer()->tool(ProductImageUploadAbortTool::class, ['upload_id' => $id])->assertOk();

        $this->assertNoPendingUploads();
        $this->finish($id)->assertHasErrors();
        $this->assertDatabaseCount('product_images', 0);
    }

    // ── recusas ─────────────────────────────────────────────────────────────

    public function test_a_chunk_with_the_wrong_hash_is_refused_and_not_stored(): void
    {
        Log::spy();
        $bytes = $this->photo();
        $chunks = $this->split($bytes);
        $id = $this->start($bytes, $chunks);

        $this->chunk($id, 0, $chunks[0], hash('sha256', 'outra coisa'))
            ->assertHasErrors()
            ->assertSee('reenvia');

        $this->assertRefusalLogged('hash_pedaco');

        $this->chunk($id, 1, $chunks[1])->assertOk()->assertSee('"received":[1]');
    }

    public function test_invalid_base64_in_a_chunk_is_refused(): void
    {
        $bytes = $this->photo();
        $chunks = $this->split($bytes);
        $id = $this->start($bytes, $chunks);

        $this->writer()->tool(ProductImageUploadChunkTool::class, [
            'upload_id' => $id,
            'index' => 0,
            'data' => '%%% nao e base64 %%%',
            'chunk_sha256' => hash('sha256', $chunks[0]),
        ])->assertHasErrors();
    }

    public function test_an_index_outside_the_count_is_refused(): void
    {
        $bytes = $this->photo();
        $chunks = $this->split($bytes);
        $id = $this->start($bytes, $chunks);

        $this->chunk($id, count($chunks), $chunks[0])->assertHasErrors();
    }

    public function test_finish_with_missing_chunks_says_which_and_keeps_the_upload(): void
    {
        Log::spy();
        $bytes = $this->photo();
        $chunks = $this->split($bytes);
        $id = $this->start($bytes, $chunks);

        foreach ($chunks as $index => $chunk) {
            if ($index !== 1) {
                $this->chunk($id, $index, $chunk)->assertOk();
            }
        }

        $this->finish($id)->assertHasErrors()->assertSee('Faltam 1 pedaços: 1.');
        $this->assertDatabaseCount('product_images', 0);
        $this->assertRefusalLogged('pedacos_em_falta');

        // O que ja chegou fica: so falta o 1.
        $this->chunk($id, 1, $chunks[1])->assertOk();
        $this->finish($id)->assertOk();
        $this->assertDatabaseCount('product_images', 1);
    }

    public function test_a_full_file_hash_mismatch_is_refused_and_discarded(): void
    {
        Log::spy();
        $bytes = $this->photo();
        $chunks = $this->split($bytes);

        $id = $this->start($bytes, $chunks, ['sha256' => hash('sha256', 'outro ficheiro')]);
        $this->sendAll($id, $chunks);

        $this->finish($id)->assertHasErrors()->assertSee('sha256');

        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNoPendingUploads();
        $this->assertRefusalLogged('hash_ficheiro');
    }

    public function test_a_total_size_mismatch_is_refused(): void
    {
        $bytes = $this->photo();
        $chunks = $this->split($bytes);

        $id = $this->start($bytes, $chunks, ['total_size' => strlen($bytes) + 1]);
        $this->sendAll($id, $chunks);

        $this->finish($id)->assertHasErrors();
        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_the_same_image_rules_apply_at_the_end(): void
    {
        Log::spy();
        $bytes = $this->photo(1200, 500);
        $chunks = $this->split($bytes);

        $id = $this->start($bytes, $chunks);
        $this->sendAll($id, $chunks);

        $this->finish($id)->assertHasErrors()->assertSee('600');
        $this->assertDatabaseCount('product_images', 0);
        $this->assertRefusalLogged('dimensoes_pequenas');
    }

    public function test_an_unknown_upload_id_is_refused(): void
    {
        $this->finish(str_repeat('a', 40))->assertHasErrors()->assertSee('product_image_upload_start');
        $this->chunk('../../etc', 0, 'x')->assertHasErrors();
    }

    public function test_an_expired_upload_is_refused_and_removed(): void
    {
        Log::spy();
        $bytes = $this->photo();
        $chunks = $this->split($bytes);
        $id = $this->start($bytes, $chunks);
        $this->sendAll($id, $chunks);

        $this->travel((int) config('mcp.images.upload_ttl_minutes') + 1)->minutes();

        $this->finish($id)->assertHasErrors();
        $this->assertDatabaseCount('product_images', 0);
        $this->assertNoPendingUploads();
        $this->assertRefusalLogged('upload_expirado');
    }

    public function test_starting_a_new_upload_prunes_expired_ones(): void
    {
        $bytes = $this->photo();
        $chunks = $this->split($bytes);
        $old = $this->start($bytes, $chunks);
        $this->chunk($old, 0, $chunks[0])->assertOk();

        $this->travel((int) config('mcp.images.upload_ttl_minutes') + 1)->minutes();
        $new = $this->start($bytes, $chunks);

        $this->assertSame(["mcp-uploads/{$new}"], Storage::disk('local')->directories('mcp-uploads'));
    }

    public function test_another_account_cannot_touch_the_upload(): void
    {
        $bytes = $this->photo();
        $chunks = $this->split($bytes);
        $id = $this->start($bytes, $chunks);
        $other = User::factory()->admin()->create();

        $this->chunk($id, 0, $chunks[0], user: $other)->assertHasErrors();
        $this->sendAll($id, $chunks);
        $this->finish($id, $other)->assertHasErrors();

        $this->assertDatabaseCount('product_images', 0);
        $this->finish($id)->assertOk();
    }

    public function test_a_variant_of_another_product_is_refused_at_start(): void
    {
        $stranger = Variant::factory()->create();
        $bytes = $this->photo();

        $this->writer()->tool(ProductImageUploadStartTool::class, [
            'product_id' => $this->product->id,
            'variant_id' => $stranger->id,
            'total_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'chunk_count' => 3,
        ])->assertHasErrors()->assertSee('variante');

        $this->assertNoPendingUploads();
    }

    public function test_an_unknown_or_archived_product_is_refused_at_start(): void
    {
        $bytes = $this->photo();
        $args = ['total_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'chunk_count' => 3];

        $this->writer()->tool(ProductImageUploadStartTool::class, ['product_id' => 999999, ...$args])->assertHasErrors();

        $this->product->update(['status' => 'archived']);
        $this->writer()->tool(ProductImageUploadStartTool::class, ['product_id' => $this->product->id, ...$args])
            ->assertHasErrors()
            ->assertSee('arquivado');

        $this->assertNoPendingUploads();
    }

    public function test_a_product_archived_mid_upload_is_refused_at_finish(): void
    {
        $bytes = $this->photo();
        $chunks = $this->split($bytes);
        $id = $this->start($bytes, $chunks);
        $this->sendAll($id, $chunks);

        $this->product->update(['status' => 'archived']);

        $this->finish($id)->assertHasErrors()->assertSee('arquivado');
        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_start_limits(): void
    {
        config(['mcp.images.max_bytes' => 1000]);

        $this->writer()->tool(ProductImageUploadStartTool::class, [
            'product_id' => $this->product->id,
            'total_size' => 1001,
            'sha256' => str_repeat('a', 64),
            'chunk_count' => 1,
        ])->assertHasErrors();

        config(['mcp.images.max_bytes' => 8 * 1024 * 1024]);

        // Pedacos a mais.
        $this->writer()->tool(ProductImageUploadStartTool::class, [
            'product_id' => $this->product->id,
            'total_size' => 500_000,
            'sha256' => str_repeat('a', 64),
            'chunk_count' => 100_000,
        ])->assertHasErrors();

        // Pedacos grandes de mais (500 KB num so).
        $this->writer()->tool(ProductImageUploadStartTool::class, [
            'product_id' => $this->product->id,
            'total_size' => 500_000,
            'sha256' => str_repeat('a', 64),
            'chunk_count' => 1,
        ])->assertHasErrors();

        // Hash mal formado.
        $this->writer()->tool(ProductImageUploadStartTool::class, [
            'product_id' => $this->product->id,
            'total_size' => 1000,
            'sha256' => 'nao-e-hex',
            'chunk_count' => 1,
        ])->assertHasErrors();

        $this->assertNoPendingUploads();
    }

    public function test_a_read_key_cannot_upload(): void
    {
        Passport::actingAs($this->admin, ['mcp:read']);

        StudioServer::actingAs($this->admin)->tool(ProductImageUploadStartTool::class, [
            'product_id' => $this->product->id,
            'total_size' => 1000,
            'sha256' => str_repeat('a', 64),
            'chunk_count' => 1,
        ])->assertNotRegistered();

        $this->assertNoPendingUploads();
    }

    // ── rasto e log ─────────────────────────────────────────────────────────

    public function test_the_base64_never_reaches_the_audit_or_the_log(): void
    {
        Log::spy();
        $bytes = $this->photo();
        $chunks = $this->split($bytes);
        $id = $this->start($bytes, $chunks);
        $this->sendAll($id, $chunks);
        $this->finish($id)->assertOk();

        $needle = substr(base64_encode($chunks[0]), 0, 40);

        foreach (McpActivity::query()->get() as $activity) {
            $this->assertStringNotContainsString($needle, (string) json_encode([$activity->arguments, $activity->changes]));
        }

        foreach (['debug', 'info', 'warning', 'error'] as $level) {
            Log::shouldNotHaveReceived($level, [Mockery::any(), Mockery::on(
                fn (mixed $context): bool => str_contains((string) json_encode($context), $needle),
            )]);
        }

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []): bool => ($context['upload_id'] ?? null) === $id && isset($context['image_id']))
            ->once();
        Log::shouldHaveReceived('debug')
            ->withArgs(fn (string $message, array $context = []): bool => ($context['upload_id'] ?? null) === $id && isset($context['index']))
            ->times(count($chunks));
    }
}
