<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;
use App\PageBuilder\StyleProp;

class HeadingBlock extends Block
{
    public function type(): string
    {
        return 'heading';
    }

    public function label(): string
    {
        return 'Título';
    }

    public function description(): string
    {
        return 'Um título, de H1 a H4.';
    }

    public function fields(): array
    {
        return [
            Field::text('text', 'Texto')->translatable()->required()->max(200),
        ];
    }

    public function styleFields(): array
    {
        return [
            StyleProp::enum('level', 'Nível', ['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4'], 'h2'),
            StyleProp::enum('size', 'Tamanho', ['sm' => 'Pequeno', 'md' => 'Médio', 'lg' => 'Grande', 'xl' => 'Muito grande', '2xl' => 'Enorme'], 'lg'),
            StyleProp::token('color', 'Cor', ['default' => 'Normal', 'muted' => 'Suave', 'accent' => 'Acento', 'gold' => 'Dourado']),
        ];
    }

    public function hasShell(): bool
    {
        return false;
    }
}
