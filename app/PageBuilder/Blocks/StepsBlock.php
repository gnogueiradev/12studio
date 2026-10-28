<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;

class StepsBlock extends Block
{
    public function type(): string
    {
        return 'steps';
    }

    public function label(): string
    {
        return 'Como funciona';
    }

    public function description(): string
    {
        return 'Passos numerados, com ícone.';
    }

    public function fields(): array
    {
        return [
            Field::text('title', 'Título')->translatable()->max(120),
            Field::textarea('subtitle', 'Texto')->translatable()->max(300),
            Field::repeater('steps', 'Passos', [
                Field::icon('icon', 'Ícone'),
                Field::text('title', 'Título')->required()->max(80),
                Field::textarea('text', 'Texto')->max(300),
            ], 6)->translatable(),
        ];
    }

    public function defaultStyle(): array
    {
        return ['bg' => 'muted', 'spacing' => 'lg', 'width' => 'contained', 'align' => 'center'];
    }
}
