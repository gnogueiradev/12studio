<?php

namespace App\PageBuilder\Blocks;

use App\Models\Category;
use App\PageBuilder\Block;
use App\PageBuilder\Field;
use App\PageBuilder\RenderContext;

class CatalogHeaderBlock extends Block
{
    public function type(): string
    {
        return 'catalog_header';
    }

    public function label(): string
    {
        return 'Cabeçalho do catálogo';
    }

    public function description(): string
    {
        return 'O título do catálogo; numa categoria, o nome e a descrição dela.';
    }

    public function templates(): array
    {
        return ['catalog'];
    }

    public function unique(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            Field::text('title', 'Título do catálogo')->translatable()->max(120)->help('Nas categorias, mostra o nome da categoria.'),
            Field::textarea('text', 'Texto do catálogo')->translatable()->max(400),
        ];
    }

    public function resolve(array $data, RenderContext $context): array
    {
        $category = $context->subject['category'] ?? null;

        if ($category instanceof Category) {
            return [
                'title' => (string) $category->translated('name', $context->locale),
                'text' => (string) $category->translated('description', $context->locale),
                'category' => true,
            ];
        }

        return $data + ['category' => false];
    }
}
