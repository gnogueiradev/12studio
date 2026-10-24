<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;

class TrustBlock extends Block
{
    public function type(): string
    {
        return 'trust';
    }

    public function label(): string
    {
        return 'Destaques de confiança';
    }

    public function description(): string
    {
        return 'Ícones curtos: "Feito em Portugal", "Envio para a UE"…';
    }

    public function fields(): array
    {
        return [
            Field::repeater('items', 'Destaques', [
                Field::icon('icon', 'Ícone'),
                Field::text('title', 'Título')->required()->max(60),
                Field::text('text', 'Texto')->max(120),
            ], 4)->translatable(),
        ];
    }

    public function defaultStyle(): array
    {
        return ['bg' => 'default', 'spacing' => 'sm', 'width' => 'contained', 'align' => 'center'];
    }
}
