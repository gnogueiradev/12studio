<?php

namespace App\Mcp\Tools\Write\Concerns;

use App\Images\ImageRefused;
use App\Mcp\Presenters\CatalogPresenter;
use App\Models\Product;
use App\Models\ProductImage;
use Closure;
use Illuminate\Support\Facades\Log;
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
