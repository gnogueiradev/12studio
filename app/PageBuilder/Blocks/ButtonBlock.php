<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;
use App\PageBuilder\StyleProp;

class ButtonBlock extends Block
{
    public function type(): string
    {
        return 'button';
    }

    public function label(): string
    {
        return 'Botão';
    }

    public function description(): string
    {
        return 'Um botão com ligação.';
    }

    public function fields(): array
    {
        return [
            Field::link('link', 'Botão')->required(),
        ];
    }

    public function styleFields(): array
    {
        return [
            StyleProp::enum('variant', 'Aspeto', ['primary' => 'Cheio', 'outline' => 'Contorno', 'link' => 'Ligação'], 'primary'),
            StyleProp::enum('size', 'Tamanho', ['sm' => 'Pequeno', 'md' => 'Médio', 'lg' => 'Grande'], 'md'),
            StyleProp::toggle('full_width', 'Largura toda'),
        ];
    }

    public function hasShell(): bool
    {
        return false;
    }
}
