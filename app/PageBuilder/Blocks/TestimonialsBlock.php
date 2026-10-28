<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;

class TestimonialsBlock extends Block
{
    public function type(): string
    {
        return 'testimonials';
    }

    public function label(): string
    {
        return 'Testemunhos';
    }

    public function description(): string
    {
        return 'O que os clientes dizem.';
    }

    public function fields(): array
    {
        return [
            Field::text('title', 'Título')->translatable()->max(120),
            Field::repeater('items', 'Testemunhos', [
                Field::textarea('quote', 'Testemunho')->required()->max(600),
                Field::text('name', 'Nome')->required()->max(80),
                Field::text('detail', 'Detalhe')->max(80)->help('Ex.: "Lisboa" ou "Comprou um candeeiro".'),
            ], 12)->translatable(),
        ];
    }
}
