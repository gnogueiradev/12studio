<?php

namespace App\Http\Requests\Translation;

use App\Http\Requests\AdminFormRequest;
use App\Services\TranslationService;

/**
 * Um produto noutro idioma, pelo separador "Traduções" do formulário. A
 * descricao e sanitizada pelo TranslationService (o mesmo perfil do
 * portugues).
 */
class ProductTranslationRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $limits = TranslationService::LIMITS;

        return [
            'name' => ['nullable', 'string', 'max:'.$limits['name']],
            'description' => ['nullable', 'string', 'max:'.$limits['description']],
            'seo_title' => ['nullable', 'string', 'max:'.$limits['seo_title']],
            'seo_description' => ['nullable', 'string', 'max:'.$limits['seo_description']],
            'personalization' => ['nullable', 'array'],
            'personalization.*' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'description' => 'descrição',
            'seo_title' => 'título para motores de busca',
            'seo_description' => 'descrição para motores de busca',
        ];
    }
}
