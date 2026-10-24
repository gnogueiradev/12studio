<?php

namespace App\PageBuilder\Blocks;

use App\Models\Product;
use App\PageBuilder\Block;
use App\PageBuilder\Field;
use App\PageBuilder\RenderContext;
use App\Store\ProductCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductGridBlock extends Block
{
    public const SOURCES = [
        'featured' => 'Destaques',
        'new' => 'Novidades',
        'sale' => 'Promoções',
        'category' => 'Uma categoria',
        'tag' => 'Uma etiqueta',
        'manual' => 'Escolha manual',
    ];

    public function type(): string
    {
        return 'product_grid';
    }

    public function label(): string
    {
        return 'Grelha de produtos';
    }

    public function description(): string
    {
        return 'Destaques, novidades, uma categoria ou produtos escolhidos.';
    }

    public function fields(): array
    {
        return [
            Field::text('title', 'Título')->translatable()->max(120),
            Field::textarea('subtitle', 'Texto')->translatable()->max(300),
            Field::link('link', 'Link "Ver tudo"'),
            Field::select('source', 'Produtos', self::SOURCES),
            Field::categories('category', 'Categoria', 1)->help('Só com "Uma categoria".'),
            Field::tags('tag', 'Etiqueta', 1)->help('Só com "Uma etiqueta".'),
            Field::products('products', 'Produtos escolhidos', 24)->help('Só com "Escolha manual", pela ordem da lista.'),
            Field::select('columns', 'Colunas', ['4' => '4', '3' => '3', '2' => '2']),
            Field::number('limit', 'Quantos', 1, 24, 8),
        ];
    }

    public function resolve(array $data, RenderContext $context): array
    {
        $query = ProductCard::query(Product::query());
        $limit = (int) $data['limit'];

        $products = match ($data['source']) {
            'manual' => $this->manual($query, $data['products']),
            'new' => $query->latest()->limit($limit)->get(),
            'sale' => $query
                ->whereHas('variants', fn (Builder $variant) => $variant->visibleInStore()
                    ->whereNotNull('compare_at_cents')
                    ->whereColumn('compare_at_cents', '>', 'price_cents'))
                ->latest()->limit($limit)->get(),
            'category' => $query->whereIn('category_id', $data['category'])->orderByDesc('featured')->latest()->limit($limit)->get(),
            'tag' => $query->whereHas('tags', fn (Builder $tag) => $tag->whereKey($data['tag']))->latest()->limit($limit)->get(),
            default => $query->where('featured', true)->latest()->limit($limit)->get(),
        };

        return [
            'title' => $data['title'],
            'subtitle' => $data['subtitle'],
            'link' => $data['link'],
            'columns' => (int) $data['columns'],
            'products' => $products->map(fn (Product $product): array => ProductCard::make($product, $context->locale))->values()->all(),
        ];
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<int, int>  $ids
     * @return Collection<int, Product>
     */
    private function manual(Builder $query, array $ids)
    {
        if ($ids === []) {
            return collect();
        }

        $found = $query->whereKey($ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $found->get($id))->filter()->values();
    }
}
