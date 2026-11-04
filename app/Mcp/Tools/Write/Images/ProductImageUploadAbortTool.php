<?php

namespace App\Mcp\Tools\Write\Images;

use App\Images\ChunkedImageUploads;
use App\Mcp\Tools\Write\Concerns\HandlesProductImages;
use App\Mcp\Tools\WriteTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('product_image_upload_abort')]
#[Title('Cancelar envio de fotografia')]
#[Description('Cancela um envio às fatias por acabar e apaga os pedaços já recebidos. Não mexe em fotografias que já estejam no produto.')]
#[IsDestructive(false)]
#[IsIdempotent]
class ProductImageUploadAbortTool extends WriteTool
{
    use HandlesProductImages;

    public function __construct(
        private ChunkedImageUploads $uploads,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'upload_id' => $schema->string()->required()->description('O upload_id devolvido pelo product_image_upload_start.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $uploadId = (string) $request->validate(['upload_id' => ['required', 'string']])['upload_id'];
        $upload = $this->uploads->find($uploadId, ChunkedImageUploads::ownerOf($user));

        if (is_string($upload)) {
            return $this->refuse($upload, 'Upload não encontrado ou já expirado: não há nada a cancelar.', [
                'upload_id' => ChunkedImageUploads::validId($uploadId) ? $uploadId : null,
            ]);
        }

        $this->uploads->forget($uploadId);

        Log::info('MCP: envio de imagem às fatias cancelado.', [
            'tool' => $this->name(),
            'upload_id' => $uploadId,
            'product_id' => $upload['product_id'],
            'variant_id' => $upload['variant_id'],
            'result' => 'ok',
        ]);

        $this->changes = ['upload_id' => $uploadId, 'product_id' => $upload['product_id']];

        return Response::structured(['message' => 'Envio cancelado.', 'upload_id' => $uploadId]);
    }
}
