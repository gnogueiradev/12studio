<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductImage\StoreProductImageRequest;
use App\Mcp\McpAuditor;
use App\Mcp\Presenters\CatalogPresenter;
use App\Mcp\ProductImageUploads;
use App\Models\McpActivity;
use App\Models\Product;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Recebe o ficheiro de um link criado pela ferramenta MCP
 * product_image_upload_url (ver App\Mcp\ProductImageUploads).
 *
 * Rota fora do grupo `web` (routes/ai.php): quem chama e um cliente HTTP, nao
 * um browser — sem sessao nem CSRF. O que a protege e a assinatura do link
 * (middleware `signed`), o prazo, e o nonce de uso unico.
 *
 * Responde sempre JSON, com mensagens que o Claude consegue mostrar tal como
 * vem.
 */
class McpProductImageUploadController extends Controller
{
    public function __invoke(
        Request $request,
        string $upload,
        ProductImageUploads $uploads,
        ImageService $images,
        McpAuditor $auditor,
    ): JsonResponse {
        $pending = $uploads->peek($upload);

        if ($pending === null) {
            return $this->fail(410, 'Este link já foi usado ou expirou. Pede outro com product_image_upload_url.');
        }

        $context = ['product_id' => $pending['product_id'], 'user_id' => $pending['user_id']];

        // A conta que pediu o link tem de continuar a poder pedi-lo: um admin
        // desativado nos 15 minutos de vida do link nao carrega nada.
        $user = User::query()->find($pending['user_id']);

        if (! $user instanceof User || ! $user->isAdmin()) {
            Log::warning('MCP image upload refused: user no longer admin', $context);
            $auditor->record($user instanceof User ? $user : null, 'product_image_upload', McpActivity::RESULT_DENIED);

            return $this->fail(403, 'Sem permissão.');
        }

        // Apagado entretanto = nao encontrado (SoftDeletes).
        $product = Product::query()->find($pending['product_id']);

        if ($product === null) {
            return $this->fail(404, 'O produto deste link já não existe (foi apagado). Recupera-o com product_restore.');
        }

        // Validar ANTES de gastar o link: um ficheiro errado nao pode obrigar
        // a pedir outro.
        $validator = Validator::make(
            $request->all(),
            ['image' => StoreProductImageRequest::fileRules()],
            [
                'image.required' => 'Falta o ficheiro: envia-o no campo "image" (multipart/form-data).',
                'image.max' => 'A fotografia tem de ter menos de 5 MB.',
                'image.mimes' => 'Só JPG, PNG ou WEBP.',
                'image.image' => 'O ficheiro não é uma imagem válida.',
            ],
        );

        if ($validator->fails()) {
            $auditor->record($user, 'product_image_upload', McpActivity::RESULT_VALIDATION_ERROR, $context);

            return $this->fail(422, 'Dados inválidos: '.collect($validator->errors()->all())->implode(' '));
        }

        if (! $uploads->consume($upload)) {
            return $this->fail(410, 'Este link já foi usado. Pede outro com product_image_upload_url.');
        }

        /** @var UploadedFile $file */
        $file = $request->file('image');

        Log::info('MCP image upload started', [...$context, 'bytes' => $file->getSize(), 'mime' => $file->getMimeType()]);

        // Disco primeiro, linha depois (ver ImageService::put). Se a linha
        // falhar, o ficheiro nao pode ficar orfao no disco.
        $paths = $images->put([$file]);

        try {
            $image = $images->attach($product, $paths, $pending['alt'])[0];

            if ($pending['make_primary'] && ! $image->is_primary) {
                $images->setPrimary($image);
                $image->refresh();
            }
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($paths);
            Log::error('MCP image upload failed', [...$context, 'exception' => $exception->getMessage()]);
            report($exception);
            $auditor->record($user, 'product_image_upload', McpActivity::RESULT_ERROR, $context);

            return $this->fail(500, 'Erro ao gravar a fotografia. Nada ficou guardado; pede outro link e tenta de novo.');
        }

        $auditor->record($user, 'product_image_upload', McpActivity::RESULT_OK, $context, [
            'product_id' => $product->id,
            'after' => ['image_id' => $image->id, 'is_primary' => $image->is_primary],
        ]);

        Log::info('MCP image upload completed', [...$context, 'image_id' => $image->id, 'is_primary' => $image->is_primary]);

        return response()->json([
            'message' => "Fotografia adicionada a \"{$product->name}\"."
                .($image->is_primary ? ' É a fotografia principal.' : ''),
            'product_id' => $product->id,
            'image' => CatalogPresenter::image($image),
        ], 201);
    }

    private function fail(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
