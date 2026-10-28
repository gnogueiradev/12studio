<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;

class BannerBlock extends Block
{
    public function type(): string
    {
        return 'banner';
    }

    public function label(): string
    {
        return 'Faixa com botão';
    }

    public function description(): string
    {
        return 'Uma chamada à ação: título curto, texto e botão.';
    }

    public function fields(): array
    {
        return [
            Field::text('title', 'Título')->translatable()->required()->max(120),
            Field::textarea('text', 'Texto')->translatable()->max(300),
            Field::link('link', 'Botão'),
            Field::image('image', 'Imagem de fundo')->help('Opcional. Fica por baixo de um véu escuro.'),
        ];
    }

    public function defaultStyle(): array
    {
        return ['bg' => 'accent', 'spacing' => 'md', 'width' => 'contained', 'align' => 'center'];
    }
}
