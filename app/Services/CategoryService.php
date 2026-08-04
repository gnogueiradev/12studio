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
     * inativa desaparece da montra; os produtos ficam (category_id preservado).
     */
    public function archive(Category $category): void
    {
        $category->update(['active' => false]);
    }
}
