<?php

namespace App\Http\Requests\Translation;

use App\Http\Requests\AdminFormRequest;

/** Uma categoria noutro idioma. */
class CategoryTranslationRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome', 'description' => 'descrição'];
    }
}
