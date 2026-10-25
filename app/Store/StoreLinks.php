<?php

namespace App\Store;

use App\Models\Category;
use App\Models\Page;
use App\PageBuilder\DefaultPages;

/**
 * Os destinos internos que o editor sugere para um link ou item de menu:
 * paginas de sistema, landings, catalogo e categorias.
 */
final class StoreLinks
{
    /**
     * @return array<int, array{label: string, path: string, group: string}>
     */
    public static function all(): array
    {
        $links = [];

        foreach (Page::SYSTEM as $key => $path) {
            if (! str_contains($path, '{')) {
                $links[] = ['label' => DefaultPages::NAMES[$key], 'path' => $path, 'group' => 'Páginas'];
            }
        }

        foreach (Page::query()->where('type', 'landing')->orderBy('name')->get(['name', 'slug']) as $page) {
            $links[] = ['label' => $page->name, 'path' => '/'.$page->slug, 'group' => 'Landing pages'];
        }

        foreach (Category::query()->whereIn('status', ['visible', 'hidden'])->orderBy('sort_order')->orderBy('name')->get(['name', 'slug']) as $category) {
            $links[] = ['label' => $category->name, 'path' => '/categoria/'.$category->slug, 'group' => 'Categorias'];
        }

        return $links;
    }
}
