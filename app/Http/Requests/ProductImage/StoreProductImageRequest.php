<?php

namespace App\Http\Requests\ProductImage;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductImageRequest extends FormRequest
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
            'images' => ['required', 'array', 'max:10'],
            // `image` valida que e mesmo uma imagem (le as dimensoes), nao so
            // que a extensao mente bem.
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.max' => 'No máximo 10 fotografias de cada vez.',
            'images.*.max' => 'Cada fotografia tem de ter menos de 5 MB.',
            'images.*.mimes' => 'Só JPG, PNG ou WEBP.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'images' => 'fotografias',
            'images.*' => 'fotografia',
        ];
    }
}
