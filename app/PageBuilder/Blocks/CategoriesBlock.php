<?php

namespace App\PageBuilder\Blocks;

use App\Models\Category;
use App\Models\Product;
use App\PageBuilder\Block;
use App\PageBuilder\Field;
use App\PageBuilder\RenderContext;
use App\Support\LocalizedUrl;

class CategoriesBlock extends Block
{
    public function type(): string
    {
        return 'categories';
    }

    public function label(): string
    {
        return 'Categorias';
    }

    public function description(): string
    {
        return 'As categorias do catálogo, com imagem.';
    }

    public function variants(): array
    {
        return ['mosaic' => 'Mosaico', 'carousel' => 'Carrossel'];
    }

    public function fields(): array
    {
        return [
            Field::text('title', 'Título')->translatable()->max(120),
            Field::textarea('subtitle', 'Texto')->translatable()->max(300),
            Field::categories('categories', 'Categorias')->help('Vazio mostra todas as visíveis.'),
            Field::number('limit', 'Quantas', 1, 12, 6),
        ];
    }

    public function resolve(array $data, RenderContext $context): array
    {
        $query = Category::query()->where('status', 'visible')->with(Category::withTranslations());

        $categories = $data['categories'] === []
            ? $query->orderBy('sort_order')->orderBy('name')->limit((int) $data['limit'])->get()
            : $query->whereKey($data['categories'])->get()
                ->sortBy(fn (Category $category): int => (int) array_search($category->id, $data['categories'], true))
                ->values();

        // A imagem de cada categoria: a principal do produto mais destacado
        // que la vive. Uma query por categoria, mas sao poucas (ate 12).
        $items = $categories->map(function (Category $category) use ($context): array {
            $product = Product::query()
                ->visibleInStore()
                ->where('category_id', $category->id)
                ->whereHas('primaryImage')
                ->with('primaryImage')
                ->orderByDesc('featured')
                ->latest()
                ->first();

            $name = (string) $category->translated('name', $context->locale);

            return [
                'name' => $name,
                'description' => $category->translated('description', $context->locale),
                'href' => LocalizedUrl::path('/categoria/'.$category->slug, $context->locale),
                'image' => $product?->primaryImage?->responsive($name),
            ];
        });

        return [
            'title' => $data['title'],
            'subtitle' => $data['subtitle'],
            'categories' => $items->values()->all(),
        ];
    }
}
