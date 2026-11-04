<?php

namespace App\Mcp\Tools\Write\Images;

use App\Images\ChunkedImageUploads;
use App\Images\ImageRefused;
use App\Images\IncomingImage;
use App\Mcp\Tools\Write\Concerns\HandlesProductImages;
use App\Mcp\Tools\WriteTool;
use App\Models\Product;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('product_image_upload_finish')]
#[Title('Acabar envio de fotografia')]
#[Description('Acaba um envio às fatias: junta os pedaços por ordem, confere o tamanho e o sha256 do ficheiro inteiro e adiciona a fotografia ao produto, exatamente como o product_image_add (JPG, PNG ou WEBP, pelo menos 600 px no lado menor, a primeira fica principal, versões reduzidas em segundo plano). Se faltarem pedaços diz quais; se o hash não bater, o upload é descartado e tem de recomeçar.')]
#[IsDestructive(false)]
class ProductImageUploadFinishTool extends WriteTool
{
    use HandlesProductImages;

    private static string $unknown = 'Upload não encontrado ou expirado: começa de novo com product_image_upload_start.';

    public function __construct(
        private ChunkedImageUploads $uploads,
        private ImageService $images,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'upload_id' => $schema->string()->required()->description('O upload_id devolvido pelo product_image_upload_start.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'upload_id' => ['required', 'string'],
        ]);

        $uploadId = (string) $data['upload_id'];

        if (! ChunkedImageUploads::validId($uploadId)) {
            return $this->refuse('upload_desconhecido', self::$unknown, ['upload_id' => null]);
        }

        // Dois finish ao mesmo tempo dariam duas fotografias: so um passa.
        $lock = Cache::lock('mcp-upload-finish:'.$uploadId, 120);

        if (! $lock->get()) {
            return $this->refuse('upload_ocupado', 'Este upload já está a ser acabado noutra chamada.', ['upload_id' => $uploadId]);
        }

        try {
            return $this->finish($uploadId, $user);
        } finally {
            $lock->release();
        }
    }

    private function finish(string $uploadId, User $user): Response|ResponseFactory
    {
        $upload = $this->uploads->find($uploadId, ChunkedImageUploads::ownerOf($user));

        if (is_string($upload)) {
            return $this->refuse($upload, self::$unknown, ['upload_id' => $uploadId]);
        }

        $context = [
            'upload_id' => $uploadId,
            'product_id' => (int) $upload['product_id'],
            'variant_id' => $upload['variant_id'] === null ? null : (int) $upload['variant_id'],
            'source' => 'chunked',
            'chunk_count' => (int) $upload['chunk_count'],
            'total_size' => (int) $upload['total_size'],
            'filename' => $upload['filename'],
        ];

        $missing = $this->uploads->progress($upload)['missing'];

        if ($missing !== []) {
            // Nao se descarta: os que faltam ainda podem chegar.
            return $this->refuse(
                'pedacos_em_falta',
                'Faltam '.count($missing).' pedaços: '.implode(', ', array_slice($missing, 0, 50)).(count($missing) > 50 ? '…' : '').'. Envia-os com product_image_upload_chunk e volta a chamar o finish.',
                [...$context, 'missing' => count($missing)],
            );
        }

        $product = Product::withTrashed()->find($context['product_id']);

        if ($product === null) {
            $this->uploads->forget($uploadId);

            return $this->refuse('produto_inexistente', 'Produto não encontrado.', $context);
        }

        $response = $this->logged($context, fn (): ResponseFactory => $this->addIncoming(
            $this->images,
            $product,
            fn (): IncomingImage => $this->assembled($upload),
            ['alt' => $upload['alt'], 'is_primary' => $upload['is_primary'], 'position' => $upload['position']],
            $context,
        ));

        // Correu bem ou foi recusado (hash, formato, produto arquivado...):
        // nos dois casos nao ha nada a aproveitar. So uma falha inesperada
        // (excecao) deixa os pedacos, para se poder tentar outra vez.
        $this->uploads->forget($uploadId);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $upload
     *
     * @throws ImageRefused
     */
    private function assembled(array $upload): IncomingImage
    {
        $path = $this->uploads->assemble($upload);
        $size = (int) filesize($path);

        if ($size !== (int) $upload['total_size']) {
            @unlink($path);

            throw new ImageRefused('tamanho_diferente', "O ficheiro junto tem {$size} bytes e o start disse {$upload['total_size']}. O upload foi descartado: recomeça.");
        }

        if (! hash_equals((string) $upload['sha256'], (string) hash_file('sha256', $path))) {
            @unlink($path);

            throw new ImageRefused('hash_ficheiro', 'O sha256 do ficheiro junto não bate com o do start. O upload foi descartado: recomeça.');
        }

        // Daqui em diante, o mesmo que o image_base64: mime, dimensoes, limites.
        return IncomingImage::fromFile($path);
    }
}
