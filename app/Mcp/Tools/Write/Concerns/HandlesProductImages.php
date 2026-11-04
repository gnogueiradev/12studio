<?php

namespace App\Mcp\Tools\Write\Concerns;

use App\Images\ImageRefused;
use App\Images\IncomingImage;
use App\Mcp\Presenters\CatalogPresenter;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ImageService;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Throwable;

/**
 * O que as ferramentas de fotografias tem em comum: o log (o canal por omissao,
 * com contexto, e nunca o base64 nem um URL inteiro), a resposta e as recusas.
 * Fica a par do rasto em mcp_activity, que diz quem chamou; este diz o que
 * aconteceu a imagem.
 */
trait HandlesProductImages
{
    private static string $productDeleted = 'Este produto foi apagado. Recupera-o primeiro com product_restore.';

    /**
     * Corre o trabalho, com recusas e falhas no log.
     *
     * @param  array<string, mixed>  $context
     * @param  Closure(): (Response|ResponseFactory)  $work
     */
    protected function logged(array $context, Closure $work): Response|ResponseFactory
    {
        try {
            return $work();
        } catch (ImageRefused $refusal) {
            return $this->refuse($refusal->reason, $refusal->getMessage(), $context);
        } catch (Throwable $exception) {
            Log::error('MCP: falha numa imagem de produto.', [
                'tool' => $this->name(),
                ...$context,
                'result' => 'falhou',
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function refuse(string $reason, string $message, array $context = []): Response
    {
        Log::warning('MCP: imagem de produto recusada.', [
            'tool' => $this->name(),
            ...$context,
            'result' => 'recusada',
            'reason' => $reason,
        ]);

        return Response::error($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logDone(array $context): void
    {
        Log::info('MCP: imagem de produto.', ['tool' => $this->name(), ...$context, 'result' => 'ok']);
    }

    /**
     * O caminho comum de uma fotografia nova: product_image_add (URL ou
     * base64) e product_image_upload_finish (as fatias, ja juntas) acabam os
     * dois aqui. Valida o produto e a variante, so depois vai buscar a imagem
     * (o `$incoming` e o download ou a descodificacao), entrega-a ao
     * ImageService e responde com a imagem e a galeria.
     *
     * @param  Closure(): IncomingImage  $incoming
     * @param  array<string, mixed>  $data  alt, is_primary, position
     * @param  array<string, mixed>  $context  product_id, variant_id e o que mais for para o log
     *
     * @throws ImageRefused
     */
    protected function addIncoming(
        ImageService $images,
        Product $product,
        Closure $incoming,
        array $data,
        array $context,
    ): ResponseFactory {
        $this->assertOpen($product);

        if ($context['variant_id'] !== null && ! $this->ownsVariant($product, (int) $context['variant_id'])) {
            throw new ImageRefused('variante_invalida', 'Essa variante não é deste produto.');
        }

        $image = $incoming();

        try {
            // O download pode ter levado 15 segundos: o produto pode ja la nao estar.
            $this->assertOpen($product->refresh());

            $created = $images->add(
                $product,
                $image->uploadedFile(),
                alt: ($data['alt'] ?? null) ?: Str::limit($product->name, 150, ''),
                variantId: $context['variant_id'] === null ? null : (int) $context['variant_id'],
                primary: isset($data['is_primary']) ? (bool) $data['is_primary'] : null,
                position: isset($data['position']) ? (int) $data['position'] : null,
            );
        } finally {
            $image->discard();
        }

        $this->logDone(['image_id' => $created->id, ...$context, ...$image->context()]);

        $this->changes = [
            'product_id' => $product->id,
            'image_id' => $created->id,
            'after' => ['is_primary' => $created->is_primary, 'position' => $created->sort_order],
        ];

        return Response::structured([
            'message' => 'Fotografia adicionada.',
            'image' => CatalogPresenter::image($created),
            'images' => $this->gallery($product),
        ]);
    }

    /**
     * @throws ImageRefused
     */
    protected function assertOpen(Product $product): void
    {
        if ($product->trashed()) {
            throw new ImageRefused('produto_apagado', self::$productDeleted);
        }

        if ($product->status === 'archived') {
            throw new ImageRefused('produto_arquivado', 'Este produto está arquivado: restaura-o primeiro com product_restore.');
        }
    }

    /**
     * O produto apagado continua a existir, e as fotografias dele tambem. O que
     * nao podem e ser mexidas: e um produto que o dono ja nao ve em lado nenhum.
     */
    protected function refusalIfDeleted(Product $product): ?Response
    {
        return $product->trashed()
            ? $this->refuse('produto_apagado', self::$productDeleted, ['product_id' => $product->id])
            : null;
    }

    protected function ownerOf(ProductImage $image): Product
    {
        return Product::withTrashed()->findOrFail($image->product_id);
    }

    protected function ownsVariant(Product $product, int $variantId): bool
    {
        return $product->variants()->whereKey($variantId)->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function gallery(Product $product): array
    {
        return $product->images()->get()
            ->map(fn (ProductImage $image): array => CatalogPresenter::image($image))
            ->values()
            ->all();
    }
}
