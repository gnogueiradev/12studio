<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImageService
{
    /**
     * Guarda o ficheiro no disco `public` e cria a linha. A primeira imagem
     * de um produto fica automaticamente como principal — sem principal, as
     * listagens, o carrinho, os emails e o OG nao sabem que imagem mostrar.
     */
    public function store(Product $product, UploadedFile $file, ?string $alt = null): ProductImage
    {
        $path = $file->store('products', 'public');

        return DB::transaction(function () use ($product, $path, $alt): ProductImage {
            $isFirst = ! $product->images()->exists();

            return $product->images()->create([
                'path' => $path,
                'alt' => $alt,
                'sort_order' => (int) $product->images()->max('sort_order') + 1,
                'is_primary' => $isFirst,
            ]);
        });
    }

    /**
     * Promove uma imagem a principal.
     *
     * A ordem importa: o indice unico PARCIAL
     * `product_images_one_primary_per_product` so permite uma linha com
     * is_primary = 1 por produto, por isso desmarca-se a antiga ANTES de
     * marcar a nova — ao contrario, a transacao rebentava a meio.
     */
    public function setPrimary(ProductImage $image): void
    {
        DB::transaction(function () use ($image): void {
            ProductImage::query()
                ->where('product_id', $image->product_id)
                ->whereKeyNot($image->getKey())
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            $image->update(['is_primary' => true]);
        });
    }

    /**
     * Reordena a galeria. Ids que nao sejam deste produto sao ignorados — a
     * ordem vem do browser e nao e de confiar.
     *
     * @param  array<int, int>  $orderedIds
     */
    public function reorder(Product $product, array $orderedIds): void
    {
        DB::transaction(function () use ($product, $orderedIds): void {
            $owned = $product->images()->pluck('id')->all();

            foreach (array_values($orderedIds) as $position => $id) {
                if (! in_array((int) $id, $owned, true)) {
                    continue;
                }

                ProductImage::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
    }

    /**
     * Apagar uma imagem e mesmo apagar: um ficheiro nao tem historial
     * comercial agarrado (ao contrario de produtos, variantes e cores).
     *
     * Se era a principal, a seguinte por ordem assume o lugar — um produto
     * com fotos nunca pode ficar sem principal.
     */
    public function delete(ProductImage $image): void
    {
        DB::transaction(function () use ($image): void {
            $wasPrimary = $image->is_primary;
            $productId = $image->product_id;

            $image->delete();

            if (! $wasPrimary) {
                return;
            }

            $next = ProductImage::query()
                ->where('product_id', $productId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            $next?->update(['is_primary' => true]);
        });

        // Fora da transacao de proposito: o disco nao participa nela, e uma
        // escrita externa dentro de DB::transaction prende o unico escritor
        // do SQLite mais tempo do que o preciso.
        Storage::disk('public')->delete($image->path);
    }
}
