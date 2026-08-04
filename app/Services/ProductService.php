<?php

namespace App\Services;

use App\Models\Product;
use App\Support\Slug;

class ProductService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): Product
    {
        $data['slug'] = Slug::unique(Product::class, (string) $data['name']);

        return Product::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        if (isset($data['name']) && $data['name'] !== $product->name) {
            $data['slug'] = Slug::unique(Product::class, (string) $data['name'], $product->id);
        }

        $product->update($data);

        return $product;
    }

    /**
     * Regra global de eliminacao: produtos nunca sao apagados fisicamente —
     * arquivar tira-os da montra mas preserva variantes, movimentos de stock
     * e itens de encomendas (historial comercial).
     */
    public function archive(Product $product): void
    {
        $product->update(['status' => 'archived']);
    }
}
