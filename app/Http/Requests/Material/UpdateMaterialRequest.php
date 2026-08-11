<?php

namespace App\Http\Requests\Material;

class UpdateMaterialRequest extends StoreMaterialRequest
{
    // Mesmas regras do store. A unicidade do nome ja ignora o proprio
    // material via StoreMaterialRequest::materialId().
}
