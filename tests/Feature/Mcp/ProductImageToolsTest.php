<?php

namespace Tests\Feature\Mcp;

use App\Images\HostResolver;
use App\Jobs\GenerateImageVariants;
use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Read\ProductGetTool;
use App\Mcp\Tools\Write\Images\ProductImageAddTool;
use App\Mcp\Tools\Write\Images\ProductImageDeleteTool;
use App\Mcp\Tools\Write\Images\ProductImagesReorderTool;
use App\Mcp\Tools\Write\Images\ProductImageUpdateTool;
use App\Models\EtsyListing;
use App\Models\EtsyListingImage;
use App\Models\McpActivity;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * As fotografias de um produto pelo Claude. Passam pelo mesmo ImageService da
 * galeria do backoffice; o que aqui ha a mais e o que so existe do lado do MCP:
 * bytes e enderecos que vem de fora.
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
        Queue::fake();

        $this->admin = User::factory()->admin()->create();
        $this->product = Product::factory()->create(['name' => 'Vaso Espiral', 'status' => 'active']);

        // O DNS a serio nunca corre nos testes.
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return [
                    'cdn.loja.example' => ['93.184.216.34'],
                    'outro.loja.example' => ['93.184.216.35', '2606:2800:220:1:248:1893:25c8:1946'],
                    'interno.example' => ['10.0.0.5'],
                    'misto.example' => ['93.184.216.34', '192.168.1.10'],
                    'v6-local.example' => ['fe80::1'],
                    'cgnat.example' => ['100.64.0.1'],
                ][$host] ?? [];
            }
        });
    }

    private function writer(): PendingTestResponse
    {
        Passport::actingAs($this->admin, ['mcp:read', 'mcp:write']);

        return StudioServer::actingAs($this->admin);
    }

    /**
     * Bytes reais de uma imagem, gerados pelo GD.
     */
    private function bytes(string $name = 'a.jpg', int $width = 800, int $height = 800): string
    {
        // Numa variavel: o ficheiro temporario morre com o objeto.
        $file = UploadedFile::fake()->image($name, $width, $height);

        return (string) file_get_contents($file->getPathname());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function addBase64(string $name = 'a.jpg', array $extra = []): mixed
    {
        return $this->writer()->tool(ProductImageAddTool::class, [
            'product_id' => $this->product->id,
            'image_base64' => base64_encode($this->bytes($name)),
            ...$extra,
        ]);
    }

    private function image(bool $primary = false, int $sort = 1): ProductImage
    {
        $path = 'products/'.fake()->uuid().'.jpg';
        Storage::disk('public')->put($path, 'x');

        return ProductImage::factory()->create([
            'product_id' => $this->product->id,
            'path' => $path,
            'is_primary' => $primary,
            'sort_order' => $sort,
        ]);
    }

    private function assertNothingWritten(): void
    {
        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function assertRefusalLogged(string $reason): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => ($context['reason'] ?? null) === $reason)
            ->atLeast()->once();
    }

    // ── product_image_add: conteudo ─────────────────────────────────────────

    /** @return array<string, array{string, string}> */
    public static function acceptedFormats(): array
    {
        return [
            'jpeg' => ['a.jpg', 'jpg'],
            'png' => ['a.png', 'png'],
            'webp' => ['a.webp', 'webp'],
        ];
    }

    #[DataProvider('acceptedFormats')]
    public function test_valid_base64_creates_the_image_and_the_file(string $name, string $extension): void
    {
        $this->addBase64($name)->assertOk()->assertSee('"is_primary":true');

        $image = ProductImage::query()->firstOrFail();

        Storage::disk('public')->assertExists($image->path);
        $this->assertStringStartsWith('products/', $image->path);
        $this->assertStringEndsWith('.'.$extension, $image->path);
        Queue::assertPushed(GenerateImageVariants::class);
    }

    public function test_a_data_uri_prefix_is_accepted(): void
    {
        $this->writer()->tool(ProductImageAddTool::class, [
            'product_id' => $this->product->id,
            'image_base64' => 'data:image/jpeg;base64,'.base64_encode($this->bytes()),
        ])->assertOk();

        $this->assertDatabaseCount('product_images', 1);
    }

    public function test_a_pdf_named_jpg_is_refused(): void
    {
        Log::spy();

        $this->writer()->tool(ProductImageAddTool::class, [
            'product_id' => $this->product->id,
            'image_base64' => base64_encode("%PDF-1.7\n1 0 obj\n<<>>\nendobj\n"),
            'filename' => 'foto.jpg',
        ])->assertHasErrors();

        $this->assertNothingWritten();
        $this->assertRefusalLogged('mime_invalido');
    }

    public function test_a_gif_is_refused(): void
    {
        $this->addBase64('a.gif')->assertHasErrors();

        $this->assertNothingWritten();
    }

    public function test_broken_base64_is_refused(): void
    {
        $this->writer()->tool(ProductImageAddTool::class, [
            'product_id' => $this->product->id,
            'image_base64' => '%%% isto nao e base64 %%%',
        ])->assertHasErrors();

        $this->assertNothingWritten();
    }

    public function test_above_the_maximum_size_is_refused_without_touching_the_disk(): void
    {
        config(['mcp.images.max_bytes' => 1000]);
        Log::spy();

        $this->addBase64()->assertHasErrors();

        $this->assertNothingWritten();
        $this->assertRefusalLogged('demasiado_grande');
    }

    public function test_below_the_minimum_side_is_refused(): void
    {
        Log::spy();

        $this->writer()->tool(ProductImageAddTool::class, [
            'product_id' => $this->product->id,
            'image_base64' => base64_encode($this->bytes('a.jpg', 1200, 500)),
        ])->assertHasErrors()->assertSee('600');

        $this->assertNothingWritten();
        $this->assertRefusalLogged('dimensoes_pequenas');
    }

    public function test_too_many_megapixels_are_refused(): void
    {
        config(['mcp.images.max_megapixels' => 0.5]);
        Log::spy();

        $this->addBase64()->assertHasErrors();

        $this->assertNothingWritten();
        $this->assertRefusalLogged('dimensoes_grandes');
    }

    public function test_exactly_one_source_is_required(): void
    {
        $this->writer()->tool(ProductImageAddTool::class, [
            'product_id' => $this->product->id,
        ])->assertHasErrors();

        $this->writer()->tool(ProductImageAddTool::class, [
            'product_id' => $this->product->id,
            'image_base64' => base64_encode($this->bytes()),
            'image_url' => 'https://cdn.loja.example/a.jpg',
        ])->assertHasErrors();

        $this->assertNothingWritten();
    }

    // ── product_image_add: regras do produto ────────────────────────────────

    public function test_the_alt_defaults_to_the_product_name(): void
    {
        $this->addBase64()->assertOk();

        $this->assertSame('Vaso Espiral', ProductImage::query()->firstOrFail()->alt);
    }

    public function test_an_alt_above_150_is_refused(): void
    {
        $this->addBase64(extra: ['alt' => str_repeat('a', 151)])->assertHasErrors();

        $this->assertNothingWritten();
    }

    public function test_the_first_image_becomes_primary_even_if_asked_otherwise(): void
    {
        $this->addBase64(extra: ['is_primary' => false])->assertOk();

        $this->assertTrue(ProductImage::query()->firstOrFail()->is_primary);
    }

    public function test_later_images_are_not_primary_by_default(): void
    {
        $first = $this->image(primary: true);

        $this->addBase64()->assertOk();

        $this->assertTrue($first->refresh()->is_primary);
        $this->assertSame(1, ProductImage::query()->where('is_primary', true)->count());
    }

    public function test_is_primary_swaps_the_primary(): void
    {
        $first = $this->image(primary: true);

        $this->addBase64(extra: ['is_primary' => true])->assertOk();

        $this->assertFalse($first->refresh()->is_primary);
        $this->assertSame(1, ProductImage::query()->where('is_primary', true)->count());
        $this->assertTrue(ProductImage::query()->latest('id')->firstOrFail()->is_primary);
    }

    public function test_position_inserts_and_moves_the_others(): void
    {
        $a = $this->image(primary: true, sort: 1);
        $b = $this->image(sort: 2);

        $this->addBase64(extra: ['position' => 2])->assertOk();

        $new = ProductImage::query()->latest('id')->firstOrFail();

        $this->assertSame(
            [$a->id, $new->id, $b->id],
            ProductImage::query()->orderBy('sort_order')->pluck('id')->all(),
        );
        $this->assertSame([1, 2, 3], ProductImage::query()->orderBy('sort_order')->pluck('sort_order')->all());
    }

    public function test_a_new_image_goes_to_the_end_by_default(): void
    {
        $this->image(primary: true, sort: 7);

        $this->addBase64()->assertOk();

        $this->assertSame(8, ProductImage::query()->latest('id')->firstOrFail()->sort_order);
    }

    public function test_a_variant_of_the_product_is_linked(): void
    {
        $variant = Variant::factory()->for($this->product)->create();

        $this->addBase64(extra: ['variant_id' => $variant->id])->assertOk();

        $this->assertSame($variant->id, ProductImage::query()->firstOrFail()->variant_id);
    }

    public function test_a_variant_of_another_product_is_refused(): void
    {
        $stranger = Variant::factory()->create();

        $this->addBase64(extra: ['variant_id' => $stranger->id])->assertHasErrors();

        $this->assertNothingWritten();
    }

    public function test_an_archived_product_is_refused(): void
    {
        $this->product->update(['status' => 'archived']);
        Log::spy();

        $this->addBase64()->assertHasErrors()->assertSee('arquivado');

        $this->assertNothingWritten();
        $this->assertRefusalLogged('produto_arquivado');
    }

    public function test_a_deleted_product_is_refused(): void
    {
        $this->product->delete();
        Log::spy();

        $this->addBase64()->assertHasErrors()->assertSee('product_restore');

        $this->assertNothingWritten();
        $this->assertRefusalLogged('produto_apagado');
    }

    public function test_the_response_brings_the_image_and_the_gallery(): void
    {
        $first = $this->image(primary: true);

        $this->addBase64(extra: ['alt' => 'De lado'])
            ->assertOk()
            ->assertSee('"alt":"De lado"')
            ->assertSee('"position":2')
            ->assertSee('"id":'.$first->id);
    }

    // ── product_image_add: URL ──────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $extra
     */
    private function addUrl(string $url, array $extra = []): mixed
    {
        return $this->writer()->tool(ProductImageAddTool::class, [
            'product_id' => $this->product->id,
            'image_url' => $url,
            ...$extra,
        ]);
    }

    public function test_a_public_url_is_downloaded(): void
    {
        Http::fake(['https://cdn.loja.example/*' => Http::response($this->bytes('a.png'))]);

        $this->addUrl('https://cdn.loja.example/a.png')->assertOk();

        $image = ProductImage::query()->firstOrFail();
        Storage::disk('public')->assertExists($image->path);
        $this->assertStringEndsWith('.png', $image->path);
    }

    public function test_a_public_redirect_is_followed(): void
    {
        Http::fake([
            'https://cdn.loja.example/*' => Http::response('', 302, ['Location' => 'https://outro.loja.example/b.png']),
            'https://outro.loja.example/*' => Http::response($this->bytes('b.png')),
        ]);

        $this->addUrl('https://cdn.loja.example/a.png')->assertOk();

        $this->assertDatabaseCount('product_images', 1);
    }

    public function test_a_relative_redirect_is_followed(): void
    {
        $bytes = $this->bytes('b.png');

        Http::fake([
            'https://cdn.loja.example/novo/*' => Http::response($bytes),
            'https://cdn.loja.example/*' => Http::response('', 301, ['Location' => '/novo/b.png']),
        ]);

        $this->addUrl('https://cdn.loja.example/a.png')->assertOk();

        $this->assertDatabaseCount('product_images', 1);
    }

    /** @return array<string, array{string}> */
    public static function blockedUrls(): array
    {
        return [
            'loopback' => ['https://127.0.0.1/a.jpg'],
            'metadados' => ['https://169.254.169.254/latest/meta-data/'],
            'privado 10' => ['https://10.1.2.3/a.jpg'],
            'privado 172' => ['https://172.16.0.1/a.jpg'],
            'privado 192' => ['https://192.168.0.1/a.jpg'],
            'cgnat literal' => ['https://100.64.0.1/a.jpg'],
            'zero' => ['https://0.0.0.0/a.jpg'],
            'ipv6 loopback' => ['https://[::1]/a.jpg'],
            'ipv6 local' => ['https://[fc00::1]/a.jpg'],
            'ipv6 link-local' => ['https://[fe80::1]/a.jpg'],
            'ipv4 mapeado' => ['https://[::ffff:127.0.0.1]/a.jpg'],
            'ipv4 compativel' => ['https://[::7f00:1]/a.jpg'],
            'ipv6 site-local' => ['https://[fec0::1]/a.jpg'],
            'nat64 local' => ['https://[64:ff9b:1::1]/a.jpg'],
            'azure wireserver' => ['https://168.63.129.16/a.jpg'],
            'decimal' => ['https://2130706433/a.jpg'],
            'hexadecimal' => ['https://0x7f.0.0.1/a.jpg'],
            'dominio privado' => ['https://interno.example/a.jpg'],
            'um endereco privado entre publicos' => ['https://misto.example/a.jpg'],
            'dominio ipv6 local' => ['https://v6-local.example/a.jpg'],
            'dominio cgnat' => ['https://cgnat.example/a.jpg'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function test_private_destinations_are_blocked(string $url): void
    {
        Http::fake();
        Log::spy();

        $this->addUrl($url)->assertHasErrors();

        Http::assertNothingSent();
        $this->assertNothingWritten();
        $this->assertRefusalLogged('ssrf_bloqueado');
    }

    /** @return array<string, array{string}> */
    public static function malformedUrls(): array
    {
        return [
            'http' => ['http://cdn.loja.example/a.jpg'],
            'ftp' => ['ftp://cdn.loja.example/a.jpg'],
            'ficheiro' => ['file:///etc/passwd'],
            'credenciais' => ['https://user:pass@cdn.loja.example/a.jpg'],
            'outra porta' => ['https://cdn.loja.example:8443/a.jpg'],
            'sem host' => ['https:///a.jpg'],
            'nome que nao existe' => ['https://nao-existe.example/a.jpg'],
        ];
    }

    #[DataProvider('malformedUrls')]
    public function test_urls_outside_the_rules_are_refused(string $url): void
    {
        Http::fake();

        $this->addUrl($url)->assertHasErrors();

        Http::assertNothingSent();
        $this->assertNothingWritten();
    }

    public function test_a_redirect_to_a_private_address_is_blocked(): void
    {
        Http::fake([
            'https://cdn.loja.example/*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data/']),
        ]);
        Log::spy();

        $this->addUrl('https://cdn.loja.example/a.jpg')->assertHasErrors();

        Http::assertSentCount(1);
        $this->assertNothingWritten();
        $this->assertRefusalLogged('ssrf_bloqueado');
    }

    public function test_a_redirect_to_a_domain_that_resolves_privately_is_blocked(): void
    {
        Http::fake([
            'https://cdn.loja.example/*' => Http::response('', 302, ['Location' => 'https://interno.example/a.jpg']),
        ]);

        $this->addUrl('https://cdn.loja.example/a.jpg')->assertHasErrors();

        Http::assertSentCount(1);
        $this->assertNothingWritten();
    }

    public function test_a_redirect_downgrading_to_http_is_blocked(): void
    {
        Http::fake([
            'https://cdn.loja.example/*' => Http::response('', 302, ['Location' => 'http://outro.loja.example/a.jpg']),
        ]);

        $this->addUrl('https://cdn.loja.example/a.jpg')->assertHasErrors();

        Http::assertSentCount(1);
        $this->assertNothingWritten();
    }

    public function test_at_most_three_redirects_are_followed(): void
    {
        Http::fake([
            'https://cdn.loja.example/*' => Http::response('', 302, ['Location' => 'https://cdn.loja.example/outra']),
        ]);
        Log::spy();

        $this->addUrl('https://cdn.loja.example/a.jpg')->assertHasErrors();

        // O pedido inicial e os tres redirects, e nem mais um.
        Http::assertSentCount(4);
        $this->assertNothingWritten();
        $this->assertRefusalLogged('redirects_a_mais');
    }

    public function test_a_download_above_the_maximum_is_refused(): void
    {
        config(['mcp.images.max_bytes' => 1000]);
        Http::fake(['https://cdn.loja.example/*' => Http::response($this->bytes())]);
        Log::spy();

        $this->addUrl('https://cdn.loja.example/a.jpg')->assertHasErrors();

        $this->assertNothingWritten();
        $this->assertRefusalLogged('demasiado_grande');
    }

    public function test_a_declared_size_above_the_maximum_is_refused(): void
    {
        config(['mcp.images.max_bytes' => 1000]);
        Http::fake(['https://cdn.loja.example/*' => Http::response('x', 200, ['Content-Length' => '999999'])]);
        Log::spy();

        $this->addUrl('https://cdn.loja.example/a.jpg')->assertHasErrors();

        $this->assertRefusalLogged('demasiado_grande');
    }

    public function test_the_content_type_of_the_server_is_not_trusted(): void
    {
        Http::fake(['https://cdn.loja.example/*' => Http::response('<?php echo 1;', 200, ['Content-Type' => 'image/jpeg'])]);
        Log::spy();

        $this->addUrl('https://cdn.loja.example/a.jpg')->assertHasErrors();

        $this->assertNothingWritten();
        $this->assertRefusalLogged('mime_invalido');
    }

    /**
     * O Http::fake() nao chega ao cURL: o que aqui se prova e que as opcoes de
     * que a seguranca depende vao mesmo no pedido. Trocar o `sink` por `stream`
     * perdia o IP fixado sem nenhum outro teste dar por isso.
     */
    public function test_the_request_goes_pinned_and_undecoded_without_proxy(): void
    {
        $seen = null;
        $bytes = $this->bytes();

        Http::fake(function ($request, array $options) use (&$seen, $bytes) {
            $seen = $options;

            return Http::response($bytes);
        });

        $this->addUrl('https://outro.loja.example/a.jpg')->assertOk();

        $this->assertSame(
            ['outro.loja.example:443:93.184.216.35,[2606:2800:220:1:248:1893:25c8:1946]'],
            $seen['curl'][CURLOPT_RESOLVE],
        );
        $this->assertSame('', $seen['proxy']);
        $this->assertFalse($seen['decode_content']);
        $this->assertFalse($seen['allow_redirects']);
        $this->assertArrayHasKey('sink', $seen);
        $this->assertArrayNotHasKey('stream', $seen);
        $this->assertSame(5, $seen['connect_timeout']);
        $this->assertLessThanOrEqual(15, $seen['timeout']);
    }

    public function test_the_download_aborts_as_soon_as_it_passes_the_maximum(): void
    {
        config(['mcp.images.max_bytes' => 1000]);

        $seen = null;

        Http::fake(function ($request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response('x');
        });

        $this->addUrl('https://cdn.loja.example/a.jpg');

        $progress = $seen['curl'][CURLOPT_PROGRESSFUNCTION];

        $this->assertSame(0, $progress(null, 0, 1000, 0, 0));
        $this->assertSame(1, $progress(null, 0, 1001, 0, 0));
        // O tamanho anunciado chega para nem comecar.
        $this->assertSame(1, $progress(null, 5000, 0, 0, 0));
    }

    public function test_a_malformed_redirect_does_not_leak_the_url(): void
    {
        Http::fake([
            'https://cdn.loja.example/*' => Http::response('', 302, ['Location' => 'https://cdn.loja.example:99999/x?token=segredo123']),
        ]);
        Log::spy();

        $this->addUrl('https://cdn.loja.example/a.jpg')->assertHasErrors();

        $this->assertNothingWritten();
        $this->assertRefusalLogged('download_falhou');
        Log::shouldNotHaveReceived('error');
    }

    public function test_temporary_files_do_not_stay_behind(): void
    {
        $count = fn (): int => count(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'mcp-img-*') ?: []);
        $before = $count();

        Http::fake([
            'https://cdn.loja.example/bom.jpg' => Http::response($this->bytes()),
            'https://cdn.loja.example/mau.jpg' => Http::response('nao sou uma imagem'),
            'https://cdn.loja.example/*' => Http::response('nope', 500),
        ]);

        $this->addUrl('https://cdn.loja.example/bom.jpg')->assertOk();
        $this->addUrl('https://cdn.loja.example/mau.jpg')->assertHasErrors();
        $this->addUrl('https://cdn.loja.example/erro.jpg')->assertHasErrors();
        $this->addBase64()->assertOk();
        $this->writer()->tool(ProductImageAddTool::class, [
            'product_id' => $this->product->id,
            'image_base64' => base64_encode('nao sou uma imagem'),
        ])->assertHasErrors();

        $this->assertSame($before, $count());
    }

    public function test_a_product_deleted_during_the_download_is_refused(): void
    {
        $bytes = $this->bytes();

        Http::fake(function () use ($bytes) {
            Product::query()->whereKey($this->product->id)->update(['deleted_at' => now()]);

            return Http::response($bytes);
        });

        $this->addUrl('https://cdn.loja.example/a.jpg')->assertHasErrors()->assertSee('product_restore');

        $this->assertNothingWritten();
    }

    public function test_a_timeout_is_reported(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        Log::spy();

        $this->addUrl('https://cdn.loja.example/a.jpg')->assertHasErrors();

        $this->assertNothingWritten();
        $this->assertRefusalLogged('timeout');
    }

    public function test_a_server_error_is_refused(): void
    {
        Http::fake(['https://cdn.loja.example/*' => Http::response('nope', 404)]);
        Log::spy();

        $this->addUrl('https://cdn.loja.example/a.jpg')->assertHasErrors();

        $this->assertNothingWritten();
        $this->assertRefusalLogged('download_falhou');
    }

    // ── Rasto ───────────────────────────────────────────────────────────────

    public function test_success_is_logged_with_context_but_without_the_query_string(): void
    {
        Http::fake(['https://cdn.loja.example/*' => Http::response($this->bytes())]);
        Log::spy();

        $this->addUrl('https://cdn.loja.example/a.jpg?token=segredo123')->assertOk();

        $image = ProductImage::query()->firstOrFail();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) use ($image): bool {
            return ($context['tool'] ?? null) === 'product_image_add'
                && $context['image_id'] === $image->id
                && $context['product_id'] === $this->product->id
                && $context['source'] === 'url'
                && $context['host'] === 'cdn.loja.example'
                && $context['mime'] === 'image/jpeg'
                && $context['width'] === 800
                && $context['bytes'] > 0
                && ! str_contains((string) json_encode($context), 'segredo123');
        })->once();
    }

    public function test_a_refusal_never_logs_the_query_string(): void
    {
        Http::fake();
        Log::spy();

        $this->addUrl('https://169.254.169.254/x?token=segredo123')->assertHasErrors();

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []): bool => ($context['host'] ?? null) === '169.254.169.254'
                && ! str_contains((string) json_encode($context), 'segredo123'),
        );
    }

    public function test_the_activity_trail_keeps_no_base64_and_no_full_url(): void
    {
        Http::fake(['https://cdn.loja.example/*' => Http::response($this->bytes())]);

        $this->addBase64()->assertOk();
        $this->addUrl('https://cdn.loja.example/a.jpg?token=segredo123')->assertOk();

        $trail = McpActivity::query()->where('tool', 'product_image_add')->get();

        $this->assertCount(2, $trail);
        $this->assertArrayNotHasKey('image_base64', $trail[0]->arguments);
        $this->assertSame('cdn.loja.example', $trail[1]->arguments['image_url']);
    }

    public function test_a_failure_while_saving_removes_the_file(): void
    {
        Log::spy();

        ProductImage::creating(fn () => throw new \RuntimeException('BD indisponivel'));

        try {
            $this->addBase64()->assertHasErrors();
        } finally {
            ProductImage::flushEventListeners();
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context = []): bool => ($context['product_id'] ?? null) === $this->product->id
                && ($context['exception'] ?? null) === \RuntimeException::class,
        );
    }

    // ── product_image_update ────────────────────────────────────────────────

    public function test_the_alt_is_updated(): void
    {
        $image = $this->image(primary: true);

        $this->writer()->tool(ProductImageUpdateTool::class, ['image_id' => $image->id, 'alt' => 'Visto de cima'])
            ->assertOk()
            ->assertSee('Visto de cima');

        $this->assertSame('Visto de cima', $image->refresh()->alt);
    }

    public function test_update_swaps_the_primary(): void
    {
        $a = $this->image(primary: true, sort: 1);
        $b = $this->image(sort: 2);

        $this->writer()->tool(ProductImageUpdateTool::class, ['image_id' => $b->id, 'is_primary' => true])->assertOk();

        $this->assertFalse($a->refresh()->is_primary);
        $this->assertTrue($b->refresh()->is_primary);
    }

    public function test_unmarking_the_primary_is_refused(): void
    {
        $image = $this->image(primary: true);

        $this->writer()->tool(ProductImageUpdateTool::class, ['image_id' => $image->id, 'is_primary' => false])
            ->assertHasErrors();

        $this->assertTrue($image->refresh()->is_primary);
    }

    public function test_update_moves_the_position(): void
    {
        $a = $this->image(primary: true, sort: 1);
        $b = $this->image(sort: 2);
        $c = $this->image(sort: 3);

        $this->writer()->tool(ProductImageUpdateTool::class, ['image_id' => $c->id, 'position' => 1])->assertOk();

        $this->assertSame(
            [$c->id, $a->id, $b->id],
            ProductImage::query()->orderBy('sort_order')->pluck('id')->all(),
        );
    }

    public function test_a_position_beyond_the_end_stays_at_the_end(): void
    {
        $a = $this->image(primary: true, sort: 1);
        $b = $this->image(sort: 2);

        $this->writer()->tool(ProductImageUpdateTool::class, ['image_id' => $a->id, 'position' => 99])->assertOk();

        $this->assertSame([$b->id, $a->id], ProductImage::query()->orderBy('sort_order')->pluck('id')->all());
    }

    public function test_update_links_and_unlinks_a_variant(): void
    {
        $image = $this->image(primary: true);
        $variant = Variant::factory()->for($this->product)->create();

        $this->writer()->tool(ProductImageUpdateTool::class, ['image_id' => $image->id, 'variant_id' => $variant->id])->assertOk();
        $this->assertSame($variant->id, $image->refresh()->variant_id);

        $this->writer()->tool(ProductImageUpdateTool::class, ['image_id' => $image->id, 'variant_id' => null])->assertOk();
        $this->assertNull($image->refresh()->variant_id);
    }

    public function test_update_refuses_a_variant_of_another_product(): void
    {
        $image = $this->image(primary: true);
        $stranger = Variant::factory()->create();

        $this->writer()->tool(ProductImageUpdateTool::class, ['image_id' => $image->id, 'variant_id' => $stranger->id])
            ->assertHasErrors();

        $this->assertNull($image->refresh()->variant_id);
    }

    public function test_update_refuses_a_deleted_product_but_not_an_archived_one(): void
    {
        $image = $this->image(primary: true);

        $this->product->update(['status' => 'archived']);
        $this->writer()->tool(ProductImageUpdateTool::class, ['image_id' => $image->id, 'alt' => 'Arrumar'])->assertOk();

        $this->product->delete();
        $this->writer()->tool(ProductImageUpdateTool::class, ['image_id' => $image->id, 'alt' => 'Outra'])
            ->assertHasErrors()
            ->assertSee('product_restore');

        $this->assertSame('Arrumar', $image->refresh()->alt);
    }

    public function test_update_without_fields_is_refused(): void
    {
        $image = $this->image(primary: true);

        $this->writer()->tool(ProductImageUpdateTool::class, ['image_id' => $image->id])->assertHasErrors();
    }

    public function test_update_of_an_unknown_image_is_refused(): void
    {
        $this->writer()->tool(ProductImageUpdateTool::class, ['image_id' => 999, 'alt' => 'x'])
            ->assertHasErrors()
            ->assertSee('não encontrada');
    }

    // ── product_image_delete ────────────────────────────────────────────────

    public function test_delete_without_confirm_only_warns(): void
    {
        $image = $this->image(primary: true);

        $this->writer()->tool(ProductImageDeleteTool::class, ['image_id' => $image->id])
            ->assertHasErrors()
            ->assertSee('confirm');

        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
        Storage::disk('public')->assertExists($image->path);
    }

    public function test_the_warning_mentions_the_etsy_listing(): void
    {
        $image = $this->image(primary: true);
        $listing = EtsyListing::query()->create(['product_id' => $this->product->id, 'title' => 'Vaso']);
        EtsyListingImage::query()->create(['listing_id' => $listing->id, 'product_image_id' => $image->id, 'rank' => 1, 'excluded' => false]);

        $this->writer()->tool(ProductImageDeleteTool::class, ['image_id' => $image->id])
            ->assertHasErrors()
            ->assertSee('Etsy');
    }

    public function test_delete_with_confirm_removes_and_promotes_the_next(): void
    {
        $a = $this->image(primary: true, sort: 1);
        $b = $this->image(sort: 2);
        $c = $this->image(sort: 3);
        $path = $a->path;

        $this->writer()->tool(ProductImageDeleteTool::class, ['image_id' => $a->id, 'confirm' => true])->assertOk();

        $this->assertDatabaseMissing('product_images', ['id' => $a->id]);
        Storage::disk('public')->assertMissing($path);
        $this->assertTrue($b->refresh()->is_primary);
        $this->assertFalse($c->refresh()->is_primary);
        // A galeria nao fica com um buraco na numeracao.
        $this->assertSame(1, $b->sort_order);
        $this->assertSame(2, $c->refresh()->sort_order);
    }

    public function test_delete_refuses_a_deleted_product(): void
    {
        $image = $this->image(primary: true);
        $this->product->delete();

        $this->writer()->tool(ProductImageDeleteTool::class, ['image_id' => $image->id, 'confirm' => true])
            ->assertHasErrors();

        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
    }

    // ── product_images_reorder ──────────────────────────────────────────────

    public function test_reorder_renumbers_the_gallery(): void
    {
        $a = $this->image(primary: true, sort: 1);
        $b = $this->image(sort: 2);

        $this->writer()->tool(ProductImagesReorderTool::class, [
            'product_id' => $this->product->id,
            'image_ids' => [$b->id, $a->id],
        ])->assertOk();

        $this->assertSame([$b->id, $a->id], ProductImage::query()->orderBy('sort_order')->pluck('id')->all());
        // A ordem nao mexe na principal.
        $this->assertTrue($a->refresh()->is_primary);
    }

    public function test_reorder_refuses_a_missing_id(): void
    {
        $a = $this->image(primary: true, sort: 1);
        $b = $this->image(sort: 2);

        $this->writer()->tool(ProductImagesReorderTool::class, [
            'product_id' => $this->product->id,
            'image_ids' => [$b->id],
        ])->assertHasErrors()->assertSee((string) $a->id);

        $this->assertSame(2, $b->refresh()->sort_order);
    }

    public function test_reorder_refuses_an_id_of_another_product(): void
    {
        $a = $this->image(primary: true, sort: 1);
        $stranger = ProductImage::factory()->create();
        $before = $stranger->sort_order;

        $this->writer()->tool(ProductImagesReorderTool::class, [
            'product_id' => $this->product->id,
            'image_ids' => [$a->id, $stranger->id],
        ])->assertHasErrors()->assertSee((string) $stranger->id);

        $this->assertSame($before, $stranger->refresh()->sort_order);
    }

    public function test_reorder_refuses_repeated_ids(): void
    {
        $a = $this->image(primary: true, sort: 1);
        $b = $this->image(sort: 2);

        $this->writer()->tool(ProductImagesReorderTool::class, [
            'product_id' => $this->product->id,
            'image_ids' => [$a->id, $a->id, $b->id],
        ])->assertHasErrors();

        $this->assertSame(1, $a->refresh()->sort_order);
    }

    // ── O que ja existia ────────────────────────────────────────────────────

    public function test_product_get_keeps_its_fields_and_gains_the_position(): void
    {
        $this->image(primary: true, sort: 4);

        $this->writer()->tool(ProductGetTool::class, ['id' => $this->product->id])
            ->assertOk()
            ->assertSee('"position":4')
            ->assertSee('"is_primary":true');
    }

    public function test_a_read_only_key_does_not_see_the_tools(): void
    {
        $image = $this->image(primary: true);

        Passport::actingAs($this->admin, ['mcp:read']);

        StudioServer::actingAs($this->admin)
            ->tool(ProductImageDeleteTool::class, ['image_id' => $image->id, 'confirm' => true])
            ->assertHasErrors();

        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
    }
}
