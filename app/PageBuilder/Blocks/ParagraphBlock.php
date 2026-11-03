<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;
use App\PageBuilder\StyleProp;

class ParagraphBlock extends Block
{
    public function type(): string
    {
        return 'paragraph';
    }

    public function label(): string
    {
        return 'Texto';
    }

    public function description(): string
    {
        return 'Um texto com formatação simples.';
    }

    public function fields(): array
    {
        return [
            Field::richtext('body', 'Texto')->translatable()->required(),
        ];
    }

    public function styleFields(): array
    {
        return [
            StyleProp::enum('size', 'Tamanho', ['sm' => 'Pequeno', 'md' => 'Médio', 'lg' => 'Grande'], 'md'),
            StyleProp::token('color', 'Cor', ['default' => 'Normal', 'muted' => 'Suave', 'accent' => 'Acento', 'gold' => 'Dourado']),
        ];
    }

    public function hasShell(): bool
    {
        return false;
    }
}
