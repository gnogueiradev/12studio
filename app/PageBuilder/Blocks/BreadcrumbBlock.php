<?php

namespace App\PageBuilder\Blocks;

use App\Models\Category;
use App\PageBuilder\Block;
use App\PageBuilder\RenderContext;
use App\Support\LocalizedUrl;

class BreadcrumbBlock extends Block
{
    public function type(): string
    {
        return 'breadcrumb';
    }

    public function label(): string
    {
        return 'Caminho (breadcrumb)';
    }

    public function description(): string
    {
        return 'Catálogo › Categoria › Produto.';
    }

    public function templates(): array
    {
        return ['product', 'catalog'];
    }

    public function unique(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [];
    }

    public function defaultStyle(): array
    {
        return ['bg' => 'default', 'spacing' => 'sm', 'width' => 'contained', 'align' => 'start'];
    }

    public function resolve(array $data, RenderContext $context): array
    {
        $items = [['label' => $context->translate('nav.catalog'), 'href' => LocalizedUrl::path('/produtos', $context->locale)]];

        $category = $context->product?->category ?? ($context->subject['category'] ?? null);

        if ($category instanceof Category) {
            $items[] = ['label' => $category->name, 'href' => LocalizedUrl::path('/categoria/'.$category->slug, $context->locale)];
        }

        if ($context->product !== null) {
            $items[] = ['label' => $context->product->name, 'href' => null];
        } elseif ($category instanceof Category) {
            $items[count($items) - 1]['href'] = null;
        }

        return ['items' => $items];
    }
}
