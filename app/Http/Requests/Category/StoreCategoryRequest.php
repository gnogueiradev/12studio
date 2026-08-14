<?php

namespace App\Http\Requests\Category;

use App\Models\Category;
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
            // Hex livre com os sete tons do design como atalhos. Foi paleta
            // fechada enquanto a cor pintava texto e um tom qualquer podia
            // deixar de se ler; agora vive numa bolinha decorativa, sem minimo
            // de contraste, e o seletor avisa quando o tom se perde no fundo.
            // A coluna e varchar(7), por isso so #rrggbb — sem alfa.
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => ['integer', 'min:0', 'max:65535'],
        ];
    }
}
