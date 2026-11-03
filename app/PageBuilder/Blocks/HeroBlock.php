<?php

namespace App\PageBuilder\Blocks;

use App\Models\Product;
use App\PageBuilder\Block;
use App\PageBuilder\Field;
use App\PageBuilder\RenderContext;

class HeroBlock extends Block
{
    public function type(): string
    {
        return 'hero';
    }

    public function label(): string
    {
        return 'Destaque principal';
    }

    public function description(): string
    {
        return 'Título grande, texto e botões, com imagem.';
    }

    public function variants(): array
    {
        return [
            'split' => 'Imagem ao lado',
            'background' => 'Imagem de fundo',
            'centered' => 'Centrado, só texto',
        ];
    }

    public function fields(): array
    {
        return [
            Field::text('eyebrow', 'Etiqueta por cima')->translatable()->max(60),
            Field::text('title', 'Título')->translatable()->required()->max(120),
            Field::textarea('subtitle', 'Texto')->translatable()->max(300),
            Field::link('primary', 'Botão principal'),
            Field::link('secondary', 'Botão secundário'),
            Field::image('image', 'Imagem')->help('Sem imagem, usa a foto de um produto em destaque.'),
        ];
    }

    public function defaultStyle(): array
    {
        return ['bg' => 'default', 'spacing' => 'lg', 'width' => 'contained', 'align' => 'start'];
    }

    public function resolve(array $data, RenderContext $context): array
    {
        if ($data['image'] === null) {
            $product = Product::query()
                ->visibleInStore()
                ->whereHas('primaryImage')
                ->with('primaryImage')
                ->orderByDesc('featured')
                ->latest()
                ->first();

            $data['image'] = $product?->primaryImage?->responsive($product->name);
        }

        return $data;
    }
}
