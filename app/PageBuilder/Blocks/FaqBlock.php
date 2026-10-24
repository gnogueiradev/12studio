<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;

class FaqBlock extends Block
{
    public function type(): string
    {
        return 'faq';
    }

    public function label(): string
    {
        return 'Perguntas frequentes';
    }

    public function description(): string
    {
        return 'Perguntas e respostas em acordeão.';
    }

    public function fields(): array
    {
        return [
            Field::text('title', 'Título')->translatable()->max(120),
            Field::repeater('items', 'Perguntas', [
                Field::text('question', 'Pergunta')->required()->max(200),
                Field::richtext('answer', 'Resposta')->required()->max(5000),
            ], 40)->translatable(),
        ];
    }
}
