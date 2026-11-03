<?php

namespace App\Http\Requests\Setting;

use App\Http\Requests\AdminFormRequest;

class UpdateBrandSettingsRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Curto de proposito: vai no cabecalho da montra, no <title> de
            // cada pagina e no assunto dos emails.
            'name' => ['required', 'string', 'max:40'],
            'tagline' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome da loja',
            'tagline' => 'frase do rodapé',
        ];
    }
}
