<?php

namespace App\Mcp\Tools\Write\Images;

use App\Images\ChunkedImageUploads;
use App\Images\ImageRefused;
use App\Mcp\Tools\Write\Concerns\HandlesProductImages;
use App\Mcp\Tools\WriteTool;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('product_image_upload_start')]
#[Title('Começar envio de fotografia às fatias')]
#[Description('Primeiro passo para enviar uma fotografia grande sem URL. Parte o ficheiro em pedaços de BYTES (uns 8–16 KB cada), e indica aqui o tamanho total em bytes, o sha256 (hex) do ficheiro inteiro e quantos pedaços vão ser. Devolve um upload_id: a seguir manda cada pedaço com product_image_upload_chunk e acaba com product_image_upload_finish. Os campos product_id, variant_id, alt, is_primary e position são os mesmos do product_image_add. O upload expira se não for acabado a tempo.')]
#[IsDestructive(false)]
class ProductImageUploadStartTool extends WriteTool
{
    use HandlesProductImages;

    public function __construct(
        private ChunkedImageUploads $uploads,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required()->description('Id do produto.'),
            'total_size' => $schema->integer()->min(1)->required()->description('Tamanho do ficheiro inteiro, em bytes (não do base64).'),
            'sha256' => $schema->string()->required()->description('sha256 do ficheiro inteiro, em hexadecimal (64 caracteres).'),
            'chunk_count' => $schema->integer()->min(1)->required()->description('Quantos pedaços vão ser enviados.'),
            'filename' => $schema->string()->max(255)->description('Nome original, só para o registo: no disco o nome é gerado.'),
            'alt' => $schema->string()->max(150)->description('Texto alternativo. Sem ele, usa-se o nome do produto.'),
            'variant_id' => $schema->integer()->description('Variante deste produto a que a fotografia pertence.'),
            'is_primary' => $schema->boolean()->description('Passa a ser a principal. Por omissão, só a primeira do produto o é.'),
            'position' => $schema->integer()->min(1)->description('Posição na galeria, a contar de 1. Por omissão, no fim.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'total_size' => ['required', 'integer', 'min:1'],
            'sha256' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'chunk_count' => ['required', 'integer', 'min:1'],
            'filename' => ['nullable', 'string', 'max:255'],
            'alt' => ['nullable', 'string', 'max:150'],
            'variant_id' => ['nullable', 'integer'],
            'is_primary' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:1'],
        ]);

        $totalSize = (int) $data['total_size'];
        $chunkCount = (int) $data['chunk_count'];

        $context = [
            'product_id' => (int) $data['product_id'],
            'variant_id' => isset($data['variant_id']) ? (int) $data['variant_id'] : null,
            'source' => 'chunked',
            'total_size' => $totalSize,
            'chunk_count' => $chunkCount,
            'filename' => isset($data['filename']) ? Str::limit(basename((string) $data['filename']), 100, '') : null,
        ];

        $product = Product::withTrashed()->find($context['product_id']);

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        return $this->logged($context, function () use ($data, $product, $context, $totalSize, $chunkCount, $user): ResponseFactory {
            $this->assertOpen($product);

            if ($context['variant_id'] !== null && ! $this->ownsVariant($product, $context['variant_id'])) {
                throw new ImageRefused('variante_invalida', 'Essa variante não é deste produto.');
            }

            $maxBytes = (int) config('mcp.images.max_bytes');
            $maxChunk = (int) config('mcp.images.upload_max_chunk_bytes');
            $maxChunks = (int) config('mcp.images.upload_max_chunks');

            if ($totalSize > $maxBytes) {
                throw new ImageRefused('demasiado_grande', 'A imagem passa do máximo de '.round($maxBytes / 1024 / 1024, 1).' MB.');
            }

            if ($chunkCount > $maxChunks || $chunkCount > $totalSize) {
                throw new ImageRefused('pedacos_invalidos', "Número de pedaços inválido: no máximo {$maxChunks}, e nunca mais do que os bytes do ficheiro.");
            }

            if ((int) ceil($totalSize / $chunkCount) > $maxChunk) {
                $minimum = (int) ceil($totalSize / $maxChunk);

                throw new ImageRefused('pedacos_grandes', "Cada pedaço pode ter no máximo {$maxChunk} bytes: para este ficheiro são precisos pelo menos {$minimum} pedaços.");
            }

            $upload = $this->uploads->start(ChunkedImageUploads::ownerOf($user), [
                'product_id' => $product->id,
                'variant_id' => $context['variant_id'],
                'alt' => $data['alt'] ?? null,
                'is_primary' => isset($data['is_primary']) ? (bool) $data['is_primary'] : null,
                'position' => isset($data['position']) ? (int) $data['position'] : null,
                'filename' => $context['filename'],
                'total_size' => $totalSize,
                'sha256' => Str::lower((string) $data['sha256']),
                'chunk_count' => $chunkCount,
            ]);

            $expires = CarbonImmutable::createFromTimestamp((int) $upload['expires_at'])->toIso8601String();

            Log::info('MCP: envio de imagem às fatias começado.', [
                'tool' => $this->name(),
                'upload_id' => $upload['id'],
                ...$context,
                'result' => 'ok',
            ]);

            $this->changes = ['product_id' => $product->id, 'upload_id' => $upload['id']];

            return Response::structured([
                'message' => "Envio começado. Manda os {$chunkCount} pedaços com product_image_upload_chunk (index de 0 a ".($chunkCount - 1).') e acaba com product_image_upload_finish.',
                'upload_id' => $upload['id'],
                'expires_at' => $expires,
                'chunk_count' => $chunkCount,
                'max_chunk_bytes' => $maxChunk,
            ]);
        });
    }
}
