<?php

namespace App\Http\Requests\ProductImage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * So o texto alternativo se edita depois do upload; o ficheiro em si
     * substitui-se apagando e voltando a carregar.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'alt' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['alt' => 'texto alternativo'];
    }
}
