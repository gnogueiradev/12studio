<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;

class TextImageBlock extends Block
{
    public function type(): string
    {
        return 'text_image';
    }

    public function label(): string
    {
        return 'Texto e imagem';
    }

    public function description(): string
    {
        return 'Texto de um lado, imagem do outro.';
    }

    public function variants(): array
    {
        return ['image_right' => 'Imagem à direita', 'image_left' => 'Imagem à esquerda'];
    }

    public function fields(): array
    {
        return [
            Field::text('eyebrow', 'Etiqueta por cima')->translatable()->max(60),
            Field::text('title', 'Título')->translatable()->max(160),
            Field::richtext('body', 'Texto')->translatable(),
            Field::link('link', 'Botão'),
            Field::image('image', 'Imagem'),
        ];
    }
}
