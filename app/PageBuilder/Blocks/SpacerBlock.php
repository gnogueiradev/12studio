<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;

class SpacerBlock extends Block
{
    public function type(): string
    {
        return 'spacer';
    }

    public function label(): string
    {
        return 'Espaço';
    }

    public function description(): string
    {
        return 'Espaço em branco entre blocos, com linha opcional.';
    }

    public function fields(): array
    {
        return [
            Field::select('size', 'Altura', ['md' => 'Média', 'sm' => 'Pequena', 'lg' => 'Grande']),
            Field::toggle('line', 'Com linha'),
        ];
    }
}
