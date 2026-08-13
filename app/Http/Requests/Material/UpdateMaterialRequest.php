<?php

namespace App\Http\Requests\Material;

use Illuminate\Support\Arr;

class UpdateMaterialRequest extends StoreMaterialRequest
{
    /**
     * Mesmas regras do store menos as cores. A unicidade do nome ja ignora o
     * proprio material via StoreMaterialRequest::materialId().
     *
     * As cores sao um atalho de criacao: um material que ja existe tem cores
     * proprias, com precos e imagens tratados em /admin/cores. Aceitar `colors`
     * aqui abria a porta a que uma gravacao do formulario de edicao lhes
     * mexesse sem querer.
     *
     * As chaves excluidas tem de acompanhar a forma do payload no store — sao
     * `colors.*.name` e `colors.*.hex` desde que o modal passou a mandar pares.
     * Falha-las nao volta a aceitar cores: deixa a edicao a rebentar na
     * validacao por causa de campos que ninguem preencheu.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return Arr::except(parent::rules(), ['colors', 'colors.*.name', 'colors.*.hex']);
    }
}
