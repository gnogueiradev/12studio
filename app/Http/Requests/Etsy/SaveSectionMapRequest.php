<?php

namespace App\Http\Requests\Etsy;

use App\Http\Requests\AdminFormRequest;

/**
 * A que seccao da loja na Etsy vai cada categoria do 12studio. E uma escolha
 * nossa: grava-se ca e so se nota no proximo envio de um anuncio.
 */
class SaveSectionMapRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // category_id => shop_section_id (ou null para "sem seccao").
            'sections' => ['present', 'array'],
            'sections.*' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
