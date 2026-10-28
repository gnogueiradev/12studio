<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\StyleProp;

class DividerBlock extends Block
{
    public function type(): string
    {
        return 'divider';
    }

    public function label(): string
    {
        return 'Divisor';
    }

    public function description(): string
    {
        return 'Uma linha a separar conteúdo.';
    }

    public function fields(): array
    {
        return [];
    }

    public function styleFields(): array
    {
        return [
            StyleProp::int('thickness', 'Espessura', 1, 8, 'px', 1),
            StyleProp::int('width', 'Largura', 10, 100, '%', 100),
            StyleProp::token('color', 'Cor', ['border' => 'Normal', 'muted' => 'Suave', 'accent' => 'Acento']),
        ];
    }

    public function hasShell(): bool
    {
        return false;
    }
}
