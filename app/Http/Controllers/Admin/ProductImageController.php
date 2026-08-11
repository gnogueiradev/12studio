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
 * por /admin/imagens/{image}. A galeria e uma seccao da pagina de edicao do
 * produto — nao tem ecra proprio.
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

        foreach ($images as $file) {
            $this->imageService->store($product, $file);
        }

        $this->toast(count($images) === 1 ? 'Fotografia adicionada.' : 'Fotografias adicionadas.');

        return to_route('admin.produtos.edit', $product);
    }

    public function update(UpdateProductImageRequest $request, ProductImage $image): RedirectResponse
    {
        $image->update($request->validated());

        $this->toast('Fotografia atualizada.');

        return to_route('admin.produtos.edit', $image->product_id);
    }

    public function setPrimary(ProductImage $image): RedirectResponse
    {
        $this->imageService->setPrimary($image);

        $this->toast('Fotografia principal alterada.');

        return to_route('admin.produtos.edit', $image->product_id);
    }

    public function reorder(ReorderProductImagesRequest $request, Product $product): RedirectResponse
    {
        /** @var array<int, int> $ids */
        $ids = $request->validated()['ids'];

        $this->imageService->reorder($product, $ids);

        return to_route('admin.produtos.edit', $product);
    }

    /**
     * Ao contrario de produtos, variantes e cores, uma fotografia apaga-se
     * mesmo: nao tem historial comercial agarrado.
     */
    public function destroy(ProductImage $image): RedirectResponse
    {
        $productId = $image->product_id;

        $this->imageService->delete($image);

        $this->toast('Fotografia apagada.');

        return to_route('admin.produtos.edit', $productId);
    }
}
