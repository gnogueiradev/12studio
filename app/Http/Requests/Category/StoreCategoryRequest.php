<?php

namespace App\Http\Requests\Category;

use App\Models\Category;
use App\Support\CategoryColors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    /**
     * Segunda camada de defesa por cima do middleware 'admin' — middleware
     * sozinho nao e seguranca (padrao do plano).
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(Category::STATUSES)],
            // Paleta fechada e nao um hex livre: a cor tem de ler nos dois
            // temas, e isso nao se valida com um regex de hex.
            'color' => ['nullable', Rule::in(CategoryColors::hexes())],
            'sort_order' => ['integer', 'min:0', 'max:65535'],
        ];
    }
}
