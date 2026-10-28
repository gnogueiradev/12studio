<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;

/**
 * O coracao da pagina de produto: galeria, preco, escolha da variante, prazo,
 * "Tenho interesse" e os separadores (descricao, especificacoes, envio). Os
 * dados do produto vem na prop de topo `product`; o bloco so guarda o
 * desenho e os textos comuns a todos os produtos.
 */
class ProductMainBlock extends Block
{
    public function type(): string
    {
        return 'product_main';
    }

    public function label(): string
    {
        return 'Produto: galeria e escolha';
    }

    public function description(): string
    {
        return 'Galeria, preço, variantes, prazo e o botão "Tenho interesse".';
    }

    public function templates(): array
    {
        return ['product'];
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
        return ['gallery_left' => 'Galeria à esquerda', 'gallery_right' => 'Galeria à direita'];
    }

    public function fields(): array
    {
        return [
            Field::select('thumbnails', 'Miniaturas', ['bottom' => 'Em baixo', 'side' => 'Ao lado']),
            Field::toggle('show_specs', 'Mostrar especificações (medidas e peso)', true),
            Field::text('shipping_title', 'Título do separador de envio')->translatable()->max(80),
            Field::richtext('shipping_text', 'Envio e devoluções')->translatable()->max(4000)
                ->help('Igual em todos os produtos.'),
            Field::textarea('custom_note', 'Nota nas peças personalizadas')->translatable()->max(400)
                ->help('Aparece só nos produtos do tipo "personalizado".'),
            Field::textarea('interest_note', 'Nota por baixo do botão')->translatable()->max(200),
        ];
    }

    public function defaultStyle(): array
    {
        return ['bg' => 'default', 'spacing' => 'sm', 'width' => 'contained', 'align' => 'start'];
    }
}
