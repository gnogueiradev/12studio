<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;
use App\PageBuilder\StyleProp;

class QuoteBlock extends Block
{
    public function type(): string
    {
        return 'quote';
    }

    public function label(): string
    {
        return 'Citação';
    }

    public function description(): string
    {
        return 'Uma citação com autor.';
    }

    public function fields(): array
    {
        return [
            Field::textarea('text', 'Citação')->translatable()->required(),
            Field::text('author', 'Autor')->translatable()->max(100),
        ];
    }

    public function styleFields(): array
    {
        return [
            StyleProp::enum('size', 'Tamanho', ['md' => 'Médio', 'lg' => 'Grande'], 'md'),
        ];
    }

    public function hasShell(): bool
    {
        return false;
    }
}
