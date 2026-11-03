<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;
use App\PageBuilder\StyleProp;

class ImageBlock extends Block
{
    public function type(): string
    {
        return 'image';
    }

    public function label(): string
    {
        return 'Imagem';
    }

    public function description(): string
    {
        return 'Uma imagem, com legenda e ligação opcionais.';
    }

    public function fields(): array
    {
        return [
            Field::image('image', 'Imagem')->required(),
            Field::text('caption', 'Legenda')->translatable()->max(200),
            Field::link('link', 'Ligação'),
        ];
    }

    public function styleFields(): array
    {
        return [
            StyleProp::enum('ratio', 'Proporção', ['auto' => 'Original', '1:1' => '1:1', '4:3' => '4:3', '16:9' => '16:9', '3:4' => '3:4'], 'auto'),
            StyleProp::int('radius', 'Cantos arredondados', 0, 48, 'px', 0),
            StyleProp::enum('fit', 'Ajuste', ['cover' => 'Preencher', 'contain' => 'Conter'], 'cover'),
        ];
    }

    public function hasShell(): bool
    {
        return false;
    }
}
