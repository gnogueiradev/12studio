<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;

/**
 * A grelha do catalogo com filtros. Os produtos e os filtros nao passam pelo
 * bloco: vem como props de topo da pagina (o "carregar mais" do Inertia junta
 * paginas numa prop de topo), e o componente le-as de la.
 */
class CatalogGridBlock extends Block
{
    public function type(): string
    {
        return 'catalog_grid';
    }

    public function label(): string
    {
        return 'Filtros e grelha';
    }

    public function description(): string
    {
        return 'Os produtos com filtros, ordenação e "carregar mais".';
    }

    public function templates(): array
    {
        return ['catalog'];
    }

    public function required(): bool
    {
        return true;
    }

    public function unique(): bool
    {
        return true;
    }

    public function variants(): array
    {
        return ['filters_side' => 'Filtros ao lado', 'filters_top' => 'Filtros em cima'];
    }

    public function fields(): array
    {
        return [
            Field::text('empty_title', 'Sem resultados: título')->translatable()->max(120),
            Field::textarea('empty_text', 'Sem resultados: texto')->translatable()->max(300),
        ];
    }

    public function defaultStyle(): array
    {
        return ['bg' => 'default', 'spacing' => 'sm', 'width' => 'contained', 'align' => 'start'];
    }
}
