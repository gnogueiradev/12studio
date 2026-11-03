<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;
use App\PageBuilder\StyleProp;

class IconBlock extends Block
{
    public function type(): string
    {
        return 'icon';
    }

    public function label(): string
    {
        return 'Ícone';
    }

    public function description(): string
    {
        return 'Um ícone, com ligação opcional.';
    }

    public function fields(): array
    {
        return [
            Field::icon('icon', 'Ícone'),
            Field::link('link', 'Ligação'),
        ];
    }

    public function styleFields(): array
    {
        return [
            StyleProp::int('size', 'Tamanho', 16, 128, 'px', 40),
            StyleProp::token('color', 'Cor', ['default' => 'Normal', 'muted' => 'Suave', 'accent' => 'Acento', 'gold' => 'Dourado']),
        ];
    }

    public function hasShell(): bool
    {
        return false;
    }
}
