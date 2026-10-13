<?php

namespace App\Http\Requests\Etsy;

use App\Http\Requests\AdminFormRequest;

/**
 * Criar ou renomear uma seccao da loja na Etsy. Uma seccao e so um titulo.
 */
class SaveShopSectionRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'section_id' => ['nullable', 'integer', 'min:1'],
            // 24 e o limite da Etsy para o titulo de uma seccao.
            'title' => ['required', 'string', 'max:24'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Dá um título à secção.',
            'title.max' => 'O título de uma secção tem no máximo 24 caracteres.',
        ];
    }
}
