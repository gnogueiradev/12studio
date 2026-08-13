<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImage\ReorderProductImagesRequest;
use App\Http\Requests\ProductImage\StoreProductImageRequest;
use App\Http\Requests\ProductImage\UpdateProductImageRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ImageService;
use Illuminate\Http\RedirectResponse;

/**
 * Fotografias vivem no contexto de um produto (rota shallow, como as
 * variantes): carregar passa por /admin/produtos/{product}/imagens, o resto
 * por /admin/imagens/{image}. A galeria e uma seccao do modal de edicao do
 * produto — nao tem ecra proprio.
 *
 * Dai o `back()` de todas as accoes: a galeria vive na listagem, com o modal
 * aberto por `?editar={id}`, e voltar ao endereco de onde se veio e o que
 * mantem o modal no sitio com a paginacao, os filtros e a pesquisa intactos.
 */
class ProductImageController extends Controller
{
    public function __construct(
        private ImageService $imageService,
    ) {}

    public function store(StoreProductImageRequest $request, Product $product): RedirectResponse
    {
        $images = $request->file('images');
        $images = is_array($images) ? $images : [$images];

        // Disco primeiro, linhas depois, numa transacao so: ver ImageService::put.
        $this->imageService->attach($product, $this->imageService->put($images));

        $this->toast(count($images) === 1 ? 'Fotografia adicionada.' : 'Fotografias adicionadas.');

        return back();
    }

    public function update(UpdateProductImageRequest $request, ProductImage $image): RedirectResponse
    {
        $image->update($request->validated());

        $this->toast('Fotografia atualizada.');

        return back();
    }

    public function setPrimary(ProductImage $image): RedirectResponse
    {
        $this->imageService->setPrimary($image);

        $this->toast('Fotografia principal alterada.');

        return back();
    }

    public function reorder(ReorderProductImagesRequest $request, Product $product): RedirectResponse
    {
        /** @var array<int, int> $ids */
        $ids = $request->validated()['ids'];

        $this->imageService->reorder($product, $ids);

        return back();
    }

    /**
     * Ao contrario de produtos, variantes e cores, uma fotografia apaga-se
     * mesmo: nao tem historial comercial agarrado.
     */
    public function destroy(ProductImage $image): RedirectResponse
    {
        $this->imageService->delete($image);

        $this->toast('Fotografia apagada.');

        return back();
    }
}
