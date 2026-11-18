<?php

namespace App\Http\Requests\Store;

use App\Http\Requests\AdminFormRequest;

/**
 * O autosave do editor. Aqui so a forma; cada bloco e validado campo a campo
 * pelo BlockSanitizer (via PageBuilderService), que conhece o esquema.
 */
class SavePageDraftRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'blocks' => ['present', 'array'],
            'seo' => ['sometimes', 'array'],
            // O marcador da versao em que o editor se baseia; "force" e o
            // "Gravar por cima" do aviso de conflito.
            'version' => ['nullable', 'string'],
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
