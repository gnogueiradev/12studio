<?php

namespace App\Services;

use App\Models\Category;
use App\Support\Slug;

class CategoryService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): Category
    {
        $data['slug'] = Slug::unique(Category::class, (string) $data['name']);

        return Category::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Category $category, array $data): Category
    {
        // O slug so muda quando o nome muda — URLs estaveis por omissao.
        if (isset($data['name']) && $data['name'] !== $category->name) {
            $data['slug'] = Slug::unique(Category::class, (string) $data['name'], $category->id);
        }

        $category->update($data);

        return $category;
    }

    /**
     * Regra global de eliminacao: nunca hard-delete — arquivar. Uma categoria
     * arquivada desaparece da montra; os produtos ficam (category_id preservado).
     */
    public function archive(Category $category): void
    {
        $category->update(['status' => 'archived']);
    }

    /**
     * Volta sempre a `visible` e nao ao estado anterior: nao guardamos de onde
     * a categoria veio, e adivinhar "oculta" para quem carregou em Restaurar
     * dava uma categoria de volta que continuava sem aparecer na loja — o
     * oposto do que o botao promete. Quem a quiser oculta muda no formulario.
     */
    public function restore(Category $category): void
    {
        $category->update(['status' => 'visible']);
    }
}
