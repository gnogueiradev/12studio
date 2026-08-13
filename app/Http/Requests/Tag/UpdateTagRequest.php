<?php

namespace App\Http\Requests\Tag;

use Illuminate\Support\Arr;

class UpdateTagRequest extends StoreTagRequest
{
    /**
     * O `scope` sai: uma etiqueta nao muda de ambito depois de criada. Move-la
     * deixava os usos existentes a apontar para o pivot errado, e nao ha
     * resposta certa para o que fazer com eles. Para mover, cria-se no ambito
     * certo — o nome esta livre la, por definicao.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return Arr::except(parent::rules(), ['scope']);
    }
}
