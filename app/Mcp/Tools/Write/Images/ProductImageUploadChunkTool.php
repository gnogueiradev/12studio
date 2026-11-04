<?php

namespace App\Mcp\Tools\Write\Images;

use App\Images\ChunkedImageUploads;
use App\Mcp\CurrentToken;
use App\Mcp\Tools\Write\Concerns\HandlesProductImages;
use App\Mcp\Tools\WriteTool;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('product_image_upload_chunk')]
#[Title('Enviar pedaço de fotografia')]
#[Description('Envia um pedaço de uma fotografia começada com product_image_upload_start. O data é o base64 só dos bytes deste pedaço (cada pedaço codifica-se à parte; sem prefixo data:), e o chunk_sha256 é o sha256 (hex) desses bytes já descodificados — se não bater, o pedaço é recusado e basta reenviá-lo. Pedaços de uns 8–16 KB. Pode reenviar-se o mesmo index as vezes que for preciso. A resposta diz que pedaços já chegaram e quais faltam.')]
#[IsDestructive(false)]
#[IsIdempotent]
class ProductImageUploadChunkTool extends WriteTool
{
    use HandlesProductImages;

    /**
     * Os argumentos ficam fora do rasto: o `data` e o grosso do pedido, e um
     * pedaco nao altera nada que se veja. O que importa (upload, index,
     * tamanho) vai em `changes` e no log. Sem argumentos, os pedacos que
     * correm bem vao no resumo de 15 minutos do Discord em vez de um alerta
     * cada (seriam dezenas por fotografia); o start e o finish avisam um a um.
     */
    protected bool $auditArguments = false;

    public function __construct(
        private ChunkedImageUploads $uploads,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'upload_id' => $schema->string()->required()->description('O upload_id devolvido pelo product_image_upload_start.'),
            'index' => $schema->integer()->min(0)->required()->description('Posição do pedaço, a contar de 0.'),
            'data' => $schema->string()->required()->description('Base64 dos bytes deste pedaço (só deste).'),
            'chunk_sha256' => $schema->string()->required()->description('sha256 dos bytes deste pedaço, já descodificados, em hexadecimal.'),
        ];
    }

    /**
     * Um upload sao dezenas de pedacos seguidos: com o limite geral das
     * escritas (20/min) nunca se acabava um. Os pedacos tem um contador so
     * deles; o resto das escritas fica onde estava.
     */
    protected function deny(User $user): ?string
    {
        if (! $user->tokenCan(ApiKeyService::SCOPE_WRITE)) {
            return 'Esta chave é só de leitura.';
        }

        $key = 'mcp-upload-chunk:'.(CurrentToken::id($user) ?? 'user-'.$user->getKey());

        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('mcp.images.upload_chunks_per_minute')))) {
            return 'Demasiados pedaços seguidos. Espera um minuto e reenvia os que faltam.';
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'upload_id' => ['required', 'string'],
            'index' => ['required', 'integer', 'min:0'],
            'data' => ['required', 'string'],
            'chunk_sha256' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
        ]);

        $uploadId = (string) $data['upload_id'];
        $index = (int) $data['index'];
        $upload = $this->uploads->find($uploadId, ChunkedImageUploads::ownerOf($user));

        $context = [
            'upload_id' => ChunkedImageUploads::validId($uploadId) ? $uploadId : Str::limit($uploadId, 40, ''),
            'index' => $index,
            'product_id' => is_array($upload) ? $upload['product_id'] : null,
            'variant_id' => is_array($upload) ? $upload['variant_id'] : null,
            'chunk_count' => is_array($upload) ? $upload['chunk_count'] : null,
            'encoded_size' => strlen((string) $data['data']),
        ];

        if (is_string($upload)) {
            return $this->refuse($upload, 'Upload não encontrado ou expirado: começa de novo com product_image_upload_start.', $context);
        }

        $count = (int) $upload['chunk_count'];

        if ($index >= $count) {
            return $this->refuse('index_invalido', 'O index vai de 0 a '.($count - 1).'.', $context);
        }

        $maxChunk = (int) config('mcp.images.upload_max_chunk_bytes');

        // Antes de descodificar, como no image_base64.
        if (strlen((string) $data['data']) > (int) ceil($maxChunk / 3) * 4 + 4) {
            return $this->refuse('pedaco_grande', "O pedaço passa do máximo de {$maxChunk} bytes.", $context);
        }

        $bytes = base64_decode((string) $data['data'], true);

        if ($bytes === false || $bytes === '') {
            return $this->refuse('base64_invalido', "O data do pedaço {$index} não é base64 válido. Reenvia-o.", $context);
        }

        $context['size'] = strlen($bytes);

        if (strlen($bytes) > $maxChunk) {
            return $this->refuse('pedaco_grande', "O pedaço passa do máximo de {$maxChunk} bytes.", $context);
        }

        if (! hash_equals(Str::lower((string) $data['chunk_sha256']), hash('sha256', $bytes))) {
            return $this->refuse('hash_pedaco', "O sha256 do pedaço {$index} não bate com o que chegou: reenvia-o.", $context);
        }

        return $this->logged($context, function () use ($uploadId, $index, $bytes, $upload, $context): ResponseFactory {
            $this->uploads->putChunk($uploadId, $index, $bytes);

            $progress = $this->uploads->progress($upload);

            Log::debug('MCP: pedaço de imagem recebido.', [
                'tool' => $this->name(),
                ...$context,
                'received' => count($progress['received']),
                'result' => 'ok',
            ]);

            $this->changes = [
                'upload_id' => $uploadId,
                'index' => $index,
                'size' => strlen($bytes),
                'received' => count($progress['received']),
                'chunk_count' => (int) $upload['chunk_count'],
            ];

            return Response::structured([
                'message' => $progress['missing'] === []
                    ? 'Chegaram todos os pedaços: acaba com product_image_upload_finish.'
                    : "Pedaço {$index} guardado.",
                'upload_id' => $uploadId,
                'received' => $progress['received'],
                'missing' => $progress['missing'],
            ]);
        });
    }
}
