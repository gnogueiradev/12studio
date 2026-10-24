<?php

namespace App\PageBuilder\Blocks;

use App\Models\Product;
use App\PageBuilder\Block;
use App\PageBuilder\Field;
use App\PageBuilder\RenderContext;
use App\Store\ProductCard;

class ProductRelatedBlock extends Block
{
    public function type(): string
    {
        return 'product_related';
    }

    public function label(): string
    {
        return 'Produtos relacionados';
    }

    public function description(): string
    {
        return 'Outros produtos da mesma categoria.';
    }

    public function templates(): array
    {
        return ['product'];
    }

    public function unique(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            Field::text('title', 'Título')->translatable()->max(120),
            Field::number('limit', 'Quantos', 2, 12, 4),
        ];
    }

    public function resolve(array $data, RenderContext $context): array
    {
        $product = $context->product;
        $limit = (int) $data['limit'];

        if ($product === null) {
            return ['title' => $data['title'], 'products' => []];
        }

        $base = fn () => ProductCard::query(Product::query())->whereKeyNot($product->id);

        $related = $product->category_id === null
            ? collect()
            : $base()->where('category_id', $product->category_id)->orderByDesc('featured')->latest()->limit($limit)->get();

        // Poucos na categoria: completa com destaques de outras.
        if ($related->count() < $limit) {
            $related = $related->concat(
                $base()->whereKeyNot($related->modelKeys())->orderByDesc('featured')->latest()->limit($limit - $related->count())->get(),
            );
        }

        return [
            'title' => $data['title'],
            'products' => $related->map(fn (Product $item): array => ProductCard::make($item, $context->locale))->values()->all(),
        ];
    }
}
