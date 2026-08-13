<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImageService
{
    /**
     * Guarda o ficheiro no disco `public` e cria a linha.
     */
    public function store(Product $product, UploadedFile $file, ?string $alt = null): ProductImage
    {
        return $this->attach($product, $this->put([$file]), $alt)[0];
    }

    /**
     * Metade "disco" do store, isolada de proposito: gravar ficheiros e criar
     * linhas sao duas coisas, e quem cria um produto COM fotografias precisa
     * de fazer a primeira antes de abrir a transacao. Ver o comentario final
     * do delete() — uma escrita no disco dentro de DB::transaction prende o
     * unico escritor do SQLite o tempo que o upload demorar.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, string>
     */
    public function put(array $files): array
    {
        return array_map(function (UploadedFile $file): string {
            $path = $file->store('products', 'public');

            // `false` e o disco a falhar (sem espaco, sem permissoes). Deixar
            // passar era criar uma linha a apontar para um ficheiro que nunca
            // existiu, e a galeria so daria pelo erro ao renderizar.
            if ($path === false) {
                throw new RuntimeException("Nao foi possivel guardar {$file->getClientOriginalName()}.");
            }

            return $path;
        }, array_values($files));
    }

    /**
     * Metade "base de dados": cria as linhas de ficheiros que ja estao no
     * disco. A primeira imagem de um produto fica automaticamente como
     * principal — sem principal, as listagens, o carrinho, os emails e o OG
     * nao sabem que imagem mostrar.
     *
     * @param  array<int, string>  $paths
     * @return array<int, ProductImage>
     */
    public function attach(Product $product, array $paths, ?string $alt = null): array
    {
        if ($paths === []) {
            return [];
        }

        return DB::transaction(function () use ($product, $paths, $alt): array {
            $isFirst = ! $product->images()->exists();
            $order = (int) $product->images()->max('sort_order');

            return array_map(
                fn (string $path, int $index): ProductImage => $product->images()->create([
                    'path' => $path,
                    'alt' => $alt,
                    'sort_order' => $order + $index + 1,
                    'is_primary' => $isFirst && $index === 0,
                ]),
                array_values($paths),
                array_keys(array_values($paths)),
            );
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
