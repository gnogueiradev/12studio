<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;

class CustomOrderBlock extends Block
{
    public function type(): string
    {
        return 'custom_order';
    }

    public function label(): string
    {
        return 'Pedido de peça personalizada';
    }

    public function description(): string
    {
        return 'O formulário em 3 passos, com envio de ficheiros.';
    }

    public function unique(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            Field::text('title', 'Título')->translatable()->max(120),
            Field::textarea('text', 'Texto')->translatable()->max(400),
        ];
    }
}
