<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;

class TextBlock extends Block
{
    public function type(): string
    {
        return 'text';
    }

    public function label(): string
    {
        return 'Texto';
    }

    public function description(): string
    {
        return 'Título e texto formatado (parágrafos, listas, links).';
    }

    public function variants(): array
    {
        return ['narrow' => 'Coluna estreita', 'wide' => 'Coluna larga'];
    }

    public function fields(): array
    {
        return [
            Field::text('title', 'Título')->translatable()->max(160),
            Field::richtext('body', 'Texto')->translatable()->required(),
        ];
    }
}
