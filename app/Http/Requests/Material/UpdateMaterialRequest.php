<?php

namespace App\Http\Requests\Material;

class UpdateMaterialRequest extends StoreMaterialRequest
{
    /**
     * Mesmas regras do store. A unicidade do nome ja ignora o proprio material
     * via StoreMaterialRequest::materialId().
     *
     * A classe deixou de divergir quando as cores sairam do formulario de
     * material — antes excluia daqui as chaves `colors.*`. Fica na mesma: e o
     * tipo que o controlador e os testes ja pedem, e o dia em que a edicao
     * voltar a divergir do criar tem sitio onde acontecer.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return parent::rules();
    }
}
