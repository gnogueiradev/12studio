<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;

class GalleryBlock extends Block
{
    public function type(): string
    {
        return 'gallery';
    }

    public function label(): string
    {
        return 'Galeria';
    }

    public function description(): string
    {
        return 'Várias imagens em grelha ou carrossel, com ampliação.';
    }

    public function variants(): array
    {
        return ['grid' => 'Grelha', 'carousel' => 'Carrossel'];
    }

    public function fields(): array
    {
        return [
            Field::text('title', 'Título')->translatable()->max(120),
            Field::repeater('images', 'Imagens', [
                Field::image('image', 'Imagem')->required(),
            ], 24),
        ];
    }
}
