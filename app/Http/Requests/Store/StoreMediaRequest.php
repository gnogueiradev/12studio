<?php

namespace App\Http\Requests\Store;

use App\Http\Requests\AdminFormRequest;

class StoreMediaRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'max:10'],
            // As mesmas regras das fotografias de produto. Sem SVG: um SVG
            // pode levar <script>, e estas imagens sao servidas da montra.
            'files.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'alt' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['files' => 'imagens', 'files.*' => 'imagem', 'alt' => 'texto alternativo'];
    }
}
