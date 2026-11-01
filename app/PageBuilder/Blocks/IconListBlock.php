<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;
use App\PageBuilder\StyleProp;

class IconListBlock extends Block
{
    public function type(): string
    {
        return 'icon_list';
    }

    public function label(): string
    {
        return 'Lista com ícones';
    }

    public function description(): string
    {
        return 'Uma lista em que cada linha tem o seu ícone.';
    }

    public function fields(): array
    {
        return [
            Field::repeater('items', 'Itens', [
                Field::icon('icon', 'Ícone'),
                Field::text('text', 'Texto')->max(200),
                Field::link('link', 'Ligação')->translatable(false),
            ], 12)->translatable(),
        ];
    }

    public function styleFields(): array
    {
        return [
            StyleProp::token('color', 'Cor dos ícones', ['default' => 'Normal', 'muted' => 'Suave', 'accent' => 'Acento', 'gold' => 'Dourado']),
            StyleProp::int('gap', 'Espaço entre itens', 0, 40, 'px', 12),
        ];
    }

    public function hasShell(): bool
    {
        return false;
    }
}
